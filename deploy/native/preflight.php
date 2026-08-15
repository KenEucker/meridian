<?php

declare(strict_types=1);

/**
 * Preflight for a native install — everything that is cheap to check and
 * expensive to discover.
 *
 * The failures this exists for do not announce themselves. An unquoted value
 * with a space makes the environment file unparseable, so the first thing that
 * fails is Composer's `package:discover` with a dotenv error and no line
 * number. An APP_URL still on the documentation domain gets all the way to
 * `meridian:event-mode` at the end of a release. A wrong database password
 * spends sixty seconds in the release's wait loop and then says only that the
 * database did not become reachable. Each one costs an install to find.
 *
 * So this runs before the release does any work, reports every problem it can
 * see at once rather than stopping at the first, and names the file and line.
 * It is also worth running on its own between the walkthrough's steps:
 *
 *     sudo php8.5 deploy/native/preflight.php
 *
 * Deliberately dependency-free. It has to work before `composer install` has
 * ever run, which is exactly when the environment file is most likely to be
 * wrong — so it implements the parse rules itself and only borrows phpdotenv,
 * the authority, once vendor/ exists.
 *
 * Run as root: the server's environment file is 0640 www-data:www-data and the
 * proxy's is 0640 root:caddy, and this checks both against each other.
 */

const EXIT_OK = 0;
const EXIT_PROBLEMS = 1;

$root = getenv('MERIDIAN_ROOT') ?: dirname(__DIR__, 2);
$serverDir = $root.'/apps/server';
$envPath = $serverDir.'/.env';
$proxyEnvPath = getenv('MERIDIAN_PROXY_ENV') ?: '/etc/meridian-proxy.env';

/** @var list<array{level: string, where: string, message: string}> $findings */
$findings = [];

function problem(string $where, string $message): void
{
    global $findings;
    $findings[] = ['level' => 'error', 'where' => $where, 'message' => $message];
}

function warning(string $where, string $message): void
{
    global $findings;
    $findings[] = ['level' => 'warning', 'where' => $where, 'message' => $message];
}

/**
 * The dotenv rules that actually break real files, checked line by line so a
 * problem can be reported with a line number — which is the part phpdotenv's
 * own exception does not give you.
 *
 * @return array<string, string>
 */
function lintEnvFile(string $path, string $contents): array
{
    $values = [];

    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        problem($path.':1', 'Starts with a UTF-8 byte-order mark. Save the file as UTF-8 without a BOM.');
        $contents = substr($contents, 3);
    }

    if (str_contains($contents, "\r\n")) {
        warning($path, 'Has Windows (CRLF) line endings. Convert them with `sed -i \'s/\r$//\' '.$path.'`.');
    }

    foreach (explode("\n", str_replace("\r\n", "\n", $contents)) as $index => $line) {
        $number = $index + 1;
        $location = $path.':'.$number;
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $assignment = preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)(\s*)=(.*)$/', $trimmed, $matches);

        if ($assignment !== 1) {
            problem($location, 'Is not a comment, a blank line, or a NAME=value assignment: '.$trimmed);

            continue;
        }

        [, $name, $spacing, $rawValue] = $matches;

        // `KEY = value` is a parse error, not a value with spaces around it.
        if ($spacing !== '') {
            problem($location, $name.' has whitespace before its `=`. Write it as '.$name.'=value.');
        }

        $value = ltrim($rawValue);

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];

            // A quoted value has to close, or everything after it is swallowed.
            if (preg_match('/^'.$quote.'((?:\\\\.|[^'.$quote.'\\\\])*)'.$quote.'\s*(?:#.*)?$/s', $value, $quoted) !== 1) {
                problem($location, $name.' opens with '.$quote.' and does not close cleanly on the same line.');

                continue;
            }

            $values[$name] = $quoted[1];

            continue;
        }

        // An unquoted value ends at a comment, and may not contain whitespace.
        // This is the rule that took down a node: phpdotenv does not read the
        // value up to the space, it refuses the entire file.
        $bare = preg_replace('/\s+#.*$/', '', $value) ?? $value;

        if (preg_match('/\s/', $bare) === 1) {
            problem(
                $location,
                $name.' has whitespace in an unquoted value. phpdotenv refuses the whole file, so every artisan '
                .'command fails. Write it as '.$name.'="'.$bare.'".',
            );

            continue;
        }

        if (str_contains($bare, '"') || str_contains($bare, "'")) {
            problem($location, $name.' has a quote inside an unquoted value. Quote the whole value instead.');

            continue;
        }

        $values[$name] = $bare;
    }

    return $values;
}

/**
 * phpdotenv is the authority on whether the file parses. It is only available
 * once the server's dependencies are installed, which on a first install is
 * after the walkthrough's step 3 — so this confirms the lint above rather than
 * replacing it.
 */
function confirmWithPhpdotenv(string $serverDir, string $envPath): void
{
    $autoload = $serverDir.'/vendor/autoload.php';

    if (! is_file($autoload)) {
        return;
    }

    require_once $autoload;

    if (! class_exists(\Dotenv\Dotenv::class)) {
        return;
    }

    try {
        \Dotenv\Dotenv::createArrayBacked($serverDir, '.env')->load();
    } catch (\Throwable $exception) {
        problem($envPath, 'phpdotenv cannot parse this file: '.$exception->getMessage());
    }
}

// ---------------------------------------------------------------------------
// The server's environment
// ---------------------------------------------------------------------------
if (! is_file($envPath)) {
    problem($envPath, 'Does not exist. Walkthrough step 2 copies deploy/native/.env.server.example here.');
    report($findings);
}

$contents = (string) file_get_contents($envPath);
$env = lintEnvFile($envPath, $contents);
confirmWithPhpdotenv($serverDir, $envPath);

// A file that does not parse has no trustworthy values, and checking them
// anyway buries the one real problem under a list of things that only look
// unset because the line they were on was rejected. Report the syntax and stop.
if (array_filter($findings, static fn (array $f): bool => $f['level'] === 'error') !== []) {
    report($findings);
}

$permissions = fileperms($envPath) & 0777;

if (($permissions & 0004) !== 0) {
    warning($envPath, sprintf('Is world-readable (0%o). It holds this node\'s secrets; 0640 is what the walkthrough sets.', $permissions));
}

$owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($envPath))['name'] ?? '') : '';

if ($owner !== '' && $owner !== 'www-data') {
    warning($envPath, 'Is owned by '.$owner.', not www-data. The server runs as www-data and has to read it.');
}

// ---------------------------------------------------------------------------
// The values a release cannot recover from
// ---------------------------------------------------------------------------
$appKey = $env['APP_KEY'] ?? '';

if ($appKey === '') {
    problem($envPath, 'APP_KEY is empty. Walkthrough step 3 generates one: `sudo -u www-data php8.5 artisan key:generate --show`.');
} elseif (! str_starts_with($appKey, 'base64:')) {
    warning($envPath, 'APP_KEY does not look like a `base64:` key from `artisan key:generate --show`.');
}

if (($env['DB_PASSWORD'] ?? '') === '') {
    problem($envPath, 'DB_PASSWORD is empty. It is the password install-host.sh was run with.');
}

if (($env['DB_HOST'] ?? '') === 'postgres') {
    problem($envPath, 'DB_HOST is `postgres`, the Compose service name. On a host it is 127.0.0.1.');
}

$appUrl = $env['APP_URL'] ?? '';

if (! str_starts_with($appUrl, 'https://')) {
    problem(
        $envPath,
        'APP_URL is not https. A non-development node is in event mode, and `meridian:event-mode` fails the release '
        .'on plain HTTP — see the TLS section of deploy/native/README.md.',
    );
}

if (str_contains($appUrl, 'example.org') || str_contains($appUrl, 'example.com')) {
    problem($envPath, 'APP_URL is still the sample documentation domain ('.$appUrl.').');
}

if (($env['MERIDIAN_NODE_NAME'] ?? '') === '') {
    warning($envPath, 'MERIDIAN_NODE_NAME is empty. It is how this node identifies itself to the peer it pairs with.');
}

if (($env['MERIDIAN_NODE_ROLE'] ?? '') === 'development') {
    problem($envPath, 'MERIDIAN_NODE_ROLE=development is not a deployment role and disables the event-mode safeguards.');
}

if (($env['APP_DEBUG'] ?? '') !== 'false') {
    problem($envPath, 'APP_DEBUG must be false on a deployed node.');
}

if (($env['MERIDIAN_CLIENT_USE_DEV_SERVER'] ?? '') !== 'false') {
    problem($envPath, 'MERIDIAN_CLIENT_USE_DEV_SERVER must be false, or the node serves from a dev server that is not running.');
}

// ---------------------------------------------------------------------------
// The proxy half, which is a separate file and easy to leave behind
// ---------------------------------------------------------------------------
if (! is_file($proxyEnvPath)) {
    problem($proxyEnvPath, 'Does not exist. install-host.sh writes it; walkthrough step 4 edits it.');
} elseif (! is_readable($proxyEnvPath)) {
    warning($proxyEnvPath, 'Is not readable by this user. Run this preflight with sudo to check it.');
} else {
    $proxy = lintEnvFile($proxyEnvPath, (string) file_get_contents($proxyEnvPath));
    $siteAddress = $proxy['MERIDIAN_SITE_ADDRESS'] ?? '';

    if ($siteAddress === '' || str_contains($siteAddress, 'example.org')) {
        problem($proxyEnvPath, 'MERIDIAN_SITE_ADDRESS is unset or still the sample domain. Caddy serves this name.');
    }

    // A mismatch here is a node that answers on one name and builds its own
    // URLs with another: TLS warnings on the Field and Kiosk clients, and
    // signed URLs that do not verify.
    $appHost = parse_url($appUrl, PHP_URL_HOST) ?: '';
    $siteHost = parse_url(str_contains($siteAddress, '://') ? $siteAddress : 'https://'.$siteAddress, PHP_URL_HOST) ?: '';

    if ($appHost !== '' && $siteHost !== '' && $appHost !== $siteHost) {
        problem(
            $proxyEnvPath,
            'MERIDIAN_SITE_ADDRESS is '.$siteHost.' but APP_URL is '.$appHost.'. They have to be the same host.',
        );
    }
}

// ---------------------------------------------------------------------------
// The database, with the credentials as written rather than as intended
// ---------------------------------------------------------------------------
// The release waits sixty seconds on an unreachable database and then reports
// only that it was unreachable. One connection attempt here reports why.
if (extension_loaded('pdo_pgsql') && ($env['DB_PASSWORD'] ?? '') !== '') {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_PORT'] ?? '5432',
        $env['DB_DATABASE'] ?? 'meridian',
    );

    try {
        new \PDO($dsn, $env['DB_USERNAME'] ?? 'meridian', $env['DB_PASSWORD'], [\PDO::ATTR_TIMEOUT => 5]);
    } catch (\PDOException $exception) {
        problem('database', 'Cannot connect with the credentials in this .env: '.$exception->getMessage());
    }
}

report($findings);

/**
 * @param list<array{level: string, where: string, message: string}> $findings
 */
function report(array $findings): never
{
    $errors = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'error'));
    $warnings = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'warning'));

    foreach ($warnings as $finding) {
        fwrite(STDERR, 'preflight warning: '.$finding['where'].': '.$finding['message']."\n");
    }

    foreach ($errors as $finding) {
        fwrite(STDERR, 'preflight error: '.$finding['where'].': '.$finding['message']."\n");
    }

    if ($errors !== []) {
        fwrite(STDERR, "\npreflight: ".count($errors)." problem(s) would fail this release. Nothing has been changed.\n");

        exit(EXIT_PROBLEMS);
    }

    fwrite(STDOUT, "preflight: node configuration looks releasable.\n");

    exit(EXIT_OK);
}
