<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs the shipped PowerSync sync rules against the test database so the
 * replication boundary can be asserted as behavior rather than as text
 * (CLIENT-021, CLIENT-022; technical spec 9.5, 11A.7; data/API 7.3).
 *
 * `deploy/powersync/sync-config.yaml` is the only definition of what replicates
 * to a device. A test that reads it as a string can say the rules mention a
 * grant; only executing them can say which rows a given user actually receives,
 * and — the property CLIENT-022 asks for — which rows they stop receiving once
 * a role is withdrawn. So this loads the shipped file, resolves its parameter
 * queries, binds the signed subject the way PowerSync binds `auth.user_id()`,
 * and reports the record ids each stream projects.
 *
 * Two deliberate limits. The PowerSync sync-rules dialect is a restricted SQL,
 * and the queries here run through the test database's driver instead, so this
 * proves scope rather than dialect validity — `deploy/powersync/README.md`
 * documents validating the dialect against a running service. And the streams
 * are read, never written: nothing here can change what a device receives.
 */
final class PowerSyncRules
{
    /**
     * Parameter queries shared by every stream, keyed by name.
     *
     * @var array<string, string>
     */
    private array $parameters = [];

    /**
     * @var array<string, array{parameters: array<string, string>, queries: list<string>}>
     */
    private array $streams = [];

    private function __construct(string $yaml)
    {
        $this->parse($yaml);
    }

    /**
     * The rules as they ship to a node's PowerSync service.
     */
    public static function shipped(): self
    {
        $path = base_path('../../deploy/powersync/sync-config.yaml');
        $yaml = file_get_contents($path);

        if ($yaml === false) {
            throw new RuntimeException("Unable to read the PowerSync sync rules at {$path}.");
        }

        return new self($yaml);
    }

    /**
     * @return list<string>
     */
    public function streamNames(): array
    {
        return array_keys($this->streams);
    }

    /**
     * Every record id that replicates to this user, keyed by source table.
     *
     * @return array<string, list<string>>
     */
    public function projectionFor(string $userId): array
    {
        $projection = [];

        foreach ($this->streamNames() as $stream) {
            foreach ($this->projectionForStream($stream, $userId) as $table => $ids) {
                $projection[$table] = array_merge($projection[$table] ?? [], $ids);
            }
        }

        return $this->normalize($projection);
    }

    /**
     * Every record id one stream projects to this user, keyed by source table.
     *
     * @return array<string, list<string>>
     */
    public function projectionForStream(string $stream, string $userId): array
    {
        $projection = [];

        foreach ($this->queriesForStream($stream) as $query) {
            $table = $this->tableFor($query);
            $sql = $this->expand($query, $this->contextFor($stream), 0);
            $bindings = array_fill(0, substr_count($sql, self::SUBJECT), $userId);

            foreach (DB::select(str_replace(self::SUBJECT, '?', $sql), $bindings) as $row) {
                $projection[$table][] = (string) $row->id;
            }
        }

        return $this->normalize($projection);
    }

    /**
     * The record ids one stream projects from one table.
     *
     * @return list<string>
     */
    public function projectedIds(string $stream, string $userId, string $table): array
    {
        return $this->projectionForStream($stream, $userId)[$table] ?? [];
    }

    /**
     * Every permission role code the rules resolve a grant against.
     *
     * @return list<string>
     */
    public function referencedRoleCodes(): array
    {
        $codes = [];

        foreach ($this->streams as $stream => $definition) {
            foreach ([...array_values($definition['parameters']), ...$definition['queries']] as $sql) {
                preg_match_all(
                    "/FROM permission_roles WHERE code = '([a-z_]+)'/",
                    $sql,
                    $matches,
                );

                $codes = [...$codes, ...$matches[1]];
            }
        }

        sort($codes);

        return array_values(array_unique($codes));
    }

    /**
     * The parameter queries in scope for a stream: the shared ones and its own.
     *
     * @return array<string, string>
     */
    private function contextFor(string $stream): array
    {
        return [...$this->parameters, ...$this->stream($stream)['parameters']];
    }

    /**
     * @return list<string>
     */
    private function queriesForStream(string $stream): array
    {
        return $this->stream($stream)['queries'];
    }

    /**
     * @return array{parameters: array<string, string>, queries: list<string>}
     */
    private function stream(string $stream): array
    {
        if (! array_key_exists($stream, $this->streams)) {
            throw new RuntimeException("The sync rules define no stream named {$stream}.");
        }

        return $this->streams[$stream];
    }

    /**
     * Resolve `IN some_parameter_query` references into inline subqueries.
     *
     * PowerSync evaluates a named parameter query and matches the data query
     * against its result; an ordinary SQL driver has no such name to resolve, so
     * the query it stands for is substituted in its place. `INNER JOIN` and
     * `IN (` are left alone: the pattern requires whitespace and a bareword.
     */
    private function expand(string $sql, array $parameters, int $depth): string
    {
        if ($depth > self::MAX_PARAMETER_DEPTH) {
            throw new RuntimeException('The sync rules nest parameter queries beyond a sane depth.');
        }

        return preg_replace_callback(
            '/\bIN\s+([a-z_][a-z0-9_]*)/',
            function (array $match) use ($parameters, $depth): string {
                if (! array_key_exists($match[1], $parameters)) {
                    return $match[0];
                }

                return 'IN ('.$this->expand($parameters[$match[1]], $parameters, $depth + 1).')';
            },
            $sql,
        );
    }

    /**
     * The table a data query projects, which is the one it selects from.
     */
    private function tableFor(string $query): string
    {
        if (preg_match('/\bFROM\s+([a-z_][a-z0-9_]*)/', $query, $match) !== 1) {
            throw new RuntimeException("Unable to read the projected table from: {$query}");
        }

        return $match[1];
    }

    /**
     * @param  array<string, list<string>>  $projection
     * @return array<string, list<string>>
     */
    private function normalize(array $projection): array
    {
        foreach ($projection as $table => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            $projection[$table] = $ids;
        }

        ksort($projection);

        return $projection;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the file
    |--------------------------------------------------------------------------
    |
    | The server carries no YAML parser, and the sync rules do not need one: the
    | file is a mapping of names to literal block scalars, two levels deep. This
    | reads exactly that shape and refuses anything else, so a structural change
    | to the rules surfaces as a failing test rather than as a silently empty
    | projection.
    */

    private const SUBJECT = 'auth.user_id()';

    private const MAX_PARAMETER_DEPTH = 5;

    private function parse(string $yaml): void
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $yaml));
        $index = 0;

        while ($index < count($lines)) {
            if ($this->isIgnorable($lines[$index])) {
                $index++;

                continue;
            }

            if ($this->indentOf($lines[$index]) !== 0) {
                throw new RuntimeException("Unexpected indentation in the sync rules: {$lines[$index]}");
            }

            $section = rtrim(trim($lines[$index]), ':');
            $index++;

            $index = match ($section) {
                'with' => $this->readParameters($lines, $index, 2, $this->parameters),
                'streams' => $this->readStreams($lines, $index),
                default => $this->skipSection($lines, $index),
            };
        }

        if ($this->streams === []) {
            throw new RuntimeException('The sync rules define no streams.');
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, string>  $into
     */
    private function readParameters(array $lines, int $index, int $indent, array &$into): int
    {
        while ($index < count($lines)) {
            if ($this->isIgnorable($lines[$index], $indent)) {
                $index++;

                continue;
            }

            if ($this->indentOf($lines[$index]) < $indent) {
                break;
            }

            if (preg_match('/^([a-z_][a-z0-9_]*):\s*\|\s*$/', trim($lines[$index]), $match) !== 1) {
                break;
            }

            $index++;
            [$block, $index] = $this->readBlock($lines, $index, $indent);
            $into[$match[1]] = $block;
        }

        return $index;
    }

    /**
     * @param  list<string>  $lines
     */
    private function readStreams(array $lines, int $index): int
    {
        while ($index < count($lines)) {
            if ($this->isIgnorable($lines[$index], 2)) {
                $index++;

                continue;
            }

            if ($this->indentOf($lines[$index]) !== 2) {
                break;
            }

            if (preg_match('/^([a-z_][a-z0-9_]*):$/', trim($lines[$index]), $match) !== 1) {
                throw new RuntimeException("Unexpected stream declaration: {$lines[$index]}");
            }

            $index = $this->readStreamBody($lines, $index + 1, $match[1]);
        }

        return $index;
    }

    /**
     * @param  list<string>  $lines
     */
    private function readStreamBody(array $lines, int $index, string $stream): int
    {
        $parameters = [];
        $queries = [];

        while ($index < count($lines)) {
            if ($this->isIgnorable($lines[$index], 4)) {
                $index++;

                continue;
            }

            if ($this->indentOf($lines[$index]) < 4) {
                break;
            }

            $key = rtrim(trim($lines[$index]), ':');
            $index++;

            if ($key === 'with') {
                $index = $this->readParameters($lines, $index, 6, $parameters);

                continue;
            }

            if ($key === 'queries') {
                [$queries, $index] = $this->readQueries($lines, $index);

                continue;
            }

            // A scalar setting such as `auto_subscribe: true`, which carries no
            // scope of its own; `PowerSyncDeviceCacheProjectionTest` asserts it.
        }

        $this->streams[$stream] = ['parameters' => $parameters, 'queries' => $queries];

        return $index;
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: list<string>, 1: int}
     */
    private function readQueries(array $lines, int $index): array
    {
        $queries = [];

        while ($index < count($lines)) {
            if ($this->isIgnorable($lines[$index], 6)) {
                $index++;

                continue;
            }

            if ($this->indentOf($lines[$index]) !== 6 || trim($lines[$index]) !== '- |') {
                break;
            }

            $index++;
            [$block, $index] = $this->readBlock($lines, $index, 6);
            $queries[] = $block;
        }

        return [$queries, $index];
    }

    /**
     * The literal block following a `|`, dedented to its own left margin.
     *
     * @param  list<string>  $lines
     * @return array{0: string, 1: int}
     */
    private function readBlock(array $lines, int $index, int $parentIndent): array
    {
        $block = [];
        $margin = null;

        while ($index < count($lines)) {
            if (trim($lines[$index]) === '') {
                $block[] = '';
                $index++;

                continue;
            }

            $indent = $this->indentOf($lines[$index]);

            if ($indent <= $parentIndent) {
                break;
            }

            $margin ??= $indent;
            $block[] = substr($lines[$index], $margin);
            $index++;
        }

        return [rtrim(implode("\n", $block)), $index];
    }

    /**
     * @param  list<string>  $lines
     */
    private function skipSection(array $lines, int $index): int
    {
        while ($index < count($lines)) {
            if (trim($lines[$index]) !== '' && $this->indentOf($lines[$index]) === 0) {
                break;
            }

            $index++;
        }

        return $index;
    }

    /**
     * Blank lines, and comments written at or inside the level being read.
     *
     * A comment outdented past that level belongs to whatever comes next, so it
     * ends the current section rather than being skipped inside it.
     */
    private function isIgnorable(string $line, int $indent = 0): bool
    {
        if (trim($line) === '') {
            return true;
        }

        return str_starts_with(trim($line), '#') && $this->indentOf($line) >= $indent;
    }

    private function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }
}
