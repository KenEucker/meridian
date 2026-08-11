<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Modules\DomainNamespace;
use App\Domain\Modules\ModuleKey;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * Every domain namespace declares an owning module or is core (MOD-004;
 * technical spec 5.2, 15A.2; M19.11).
 *
 * The expectations are read out of the technical spec rather than restated
 * here, so a namespace added to section 5.2 or moved between modules in section
 * 15A.2 fails this test until the code says the same thing.
 */
class DomainNamespaceOwnershipTest extends TestCase
{
    public function test_every_namespace_in_technical_spec_5_2_is_declared(): void
    {
        $namespaces = $this->specList(
            '## 5.2 Modular monolith boundaries',
            'This should be a lightweight modular monolith',
        );

        $this->assertNotEmpty($namespaces, 'Technical spec 5.2 listed no domain namespaces.');

        foreach ($namespaces as $namespace) {
            $declared = DomainNamespace::tryFrom($namespace);

            $this->assertNotNull(
                $declared,
                "Technical spec 5.2 lists the [{$namespace}] namespace and DomainNamespace does not declare it. "
                .'Declare its owning module, or declare it core.',
            );
        }
    }

    public function test_module_ownership_matches_technical_spec_15a_2(): void
    {
        $expected = $this->specOwnership();

        $this->assertSame(
            ModuleKey::keys(),
            array_keys($expected),
            'Technical spec 15A.2 maps a different set of modules than the catalogue holds.',
        );

        foreach ($expected as $key => $namespaces) {
            $module = ModuleKey::from($key);

            $declared = array_map(
                static fn (DomainNamespace $namespace): string => $namespace->value,
                DomainNamespace::ownedBy($module),
            );

            sort($namespaces);
            sort($declared);

            $this->assertSame(
                $namespaces,
                $declared,
                "The namespaces technical spec 15A.2 gives [{$key}] are not the ones DomainNamespace declares for it.",
            );
        }
    }

    public function test_a_namespace_no_module_claims_is_core(): void
    {
        $core = DomainNamespace::core();

        $this->assertNotEmpty($core, 'MOD-004 requires a core that no module owns.');

        foreach ($core as $namespace) {
            $this->assertNull($namespace->module());
            $this->assertNull(DomainNamespace::ownerOf($namespace->value));
        }

        // MOD-004 names attendance, hours, and credits as core explicitly:
        // check-in does not require a shift (requirements 5.8), so they survive
        // Scheduling being inactive.
        foreach ([DomainNamespace::Attendance, DomainNamespace::Hours, DomainNamespace::Credits] as $namespace) {
            $this->assertTrue($namespace->isCore(), $namespace->value.' must not be owned by Scheduling.');
        }
    }

    public function test_an_undeclared_namespace_defaults_to_core(): void
    {
        // Technical spec 15A.2: a namespace with no declaration is core, so the
        // failure mode of forgetting to declare one is a capability that stays
        // reachable rather than one that silently disappears.
        $this->assertNull(DomainNamespace::ownerOf('a_namespace_nobody_has_declared'));
    }

    public function test_every_module_owns_at_least_one_namespace(): void
    {
        foreach (ModuleKey::cases() as $module) {
            $this->assertNotEmpty(
                DomainNamespace::ownedBy($module),
                'Module ['.$module->value.'] owns no domain namespace, so nothing can ever be gated on it.',
            );
        }
    }

    public function test_no_namespace_is_declared_twice(): void
    {
        $values = array_map(
            static fn (DomainNamespace $namespace): string => $namespace->value,
            DomainNamespace::cases(),
        );

        $this->assertSame($values, array_values(array_unique($values)));
    }

    /**
     * The `text` block between two markers in the technical spec, one entry per
     * line.
     *
     * @return list<string>
     */
    private function specList(string $after, string $before): array
    {
        $section = $this->specSection($after, $before);

        preg_match('/```text\n(.*?)```/s', $section, $matches);

        $lines = preg_split('/\R/', trim($matches[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(
            fn (string $line): string => $this->normalize($line),
            $lines,
        ));
    }

    /**
     * Technical spec 15A.2's module-to-namespace mapping, keyed by module.
     *
     * The block is one module per entry, its namespaces comma-separated and
     * wrapped onto indented continuation lines.
     *
     * @return array<string, list<string>>
     */
    private function specOwnership(): array
    {
        $section = $this->specSection('## 15A.2 Module catalogue', '## 15A.3');

        preg_match('/```text\n(.*?)```/s', $section, $matches);

        $ownership = [];
        $current = null;

        foreach (preg_split('/\R/', rtrim($matches[1] ?? '')) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^(\S+)\s+(.*)$/', $line, $entry) === 1) {
                $current = $entry[1];
                $ownership[$current] = [];
                $terms = $entry[2];
            } else {
                $terms = $line;
            }

            if ($current === null) {
                continue;
            }

            foreach (explode(',', $terms) as $term) {
                if (trim($term) === '') {
                    continue;
                }

                $ownership[$current][] = $this->normalize($term);
            }
        }

        return $ownership;
    }

    private function specSection(string $after, string $before): string
    {
        $spec = file_get_contents(dirname(__DIR__, 4).'/docs/meridian-technical-spec.md');

        $this->assertIsString($spec, 'The technical spec could not be read.');

        $start = strpos($spec, $after);
        $this->assertNotFalse($start, "The technical spec no longer contains [{$after}].");

        $end = strpos($spec, $before, $start);
        $this->assertNotFalse($end, "The technical spec no longer contains [{$before}] after [{$after}].");

        return substr($spec, $start, $end - $start);
    }

    /**
     * A spec term ("FieldReports", "Event maps", "shift signups and
     * requirements") as the namespace key that declares it.
     */
    private function normalize(string $term): string
    {
        return Str::snake(str_replace(' ', '', ucwords(trim($term))));
    }
}
