<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Http\Middleware\EnforceActiveModule;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * No module-owned route is ungated (MOD-012; data/API 5.9; M19.12).
 *
 * The expectation is read out of the data/API specification rather than
 * restated here. Section 5.9 lists the endpoint groups each module owns as path
 * patterns, and this walks the registered routes against them: an endpoint
 * added to one of those groups without a gate fails this test, which is the
 * only thing standing between MOD-012 and a surface that is hidden in the
 * client and still answers on the wire.
 *
 * It is a lower bound, deliberately. The spec lists the groups it knew about,
 * and Meridian has module-owned endpoints it does not name — the departmental
 * training and equipment reads, the deployment index, the waiver reads. Those
 * are gated too, and the test below asserting a gated route's module is one the
 * catalogue holds covers them; what this section cannot do is prove a route it
 * has never heard of belongs to a module.
 */
class ModuleRouteCoverageTest extends TestCase
{
    /**
     * Core endpoints that compose module-owned data and must never refuse on
     * module state (MOD-019; data/API 5.9).
     *
     * Named rather than derived, because "this one omits instead of refusing"
     * is a decision about each of them. M19.18 builds the omission; this keeps
     * a gate from being put on them in the meantime, which would turn one
     * inactive module into a dead dashboard.
     *
     * @var list<string>
     */
    private const NEVER_GATED = [
        'api.me',
        'api.offline-read-set',
        'api.events.dashboard',
        'api.events.event-horizon',
        'api.events.departments.overview',
        'api.events.departments.logistics',
        'api.events.departments.operations',
        'api.events.departments.planning',
        'api.events.departments.roster',
        'api.events.departments.credits',
        'api.organizations.configuration.show',
        'api.organizations.departments.index',
        'api.organizations.staff.index',
        'api.organizations.directory',
        'api.events.directory',
        'api.commands.check-in-staff',
        'api.commands.check-out-staff',
        'api.commands.mark-no-show',
        'api.commands.correct-hours',
        'api.commands.calculate-event-credits',
    ];

    public function test_every_endpoint_group_the_spec_gives_a_module_is_gated_to_it(): void
    {
        $ownership = $this->specOwnership();

        $this->assertSame(
            ModuleKey::keys(),
            array_keys($ownership),
            'Data/API 5.9 lists a different set of modules than the catalogue holds.',
        );

        $matched = 0;

        foreach ($this->routes() as $route) {
            $expected = $this->modulesMatching($route->uri(), $ownership);

            if ($expected === []) {
                continue;
            }

            $matched++;

            $declared = $this->declaredModule($route);

            $this->assertNotNull(
                $declared,
                "[{$route->uri()}] is in an endpoint group data/API 5.9 gives to ["
                .implode(', ', $expected).'] and carries no module gate. '
                .'Declare it with EnforceActiveModule::for(), or the endpoint answers '
                .'for an organization that does not run the module (MOD-012).',
            );

            // A path may sit in more than one group: `append-incident-note`
            // matches both the `*-incident*` group and the `*-note*` one, and
            // `cancel-training-signup` matches both `*-training*` and
            // `*-signup*`. The spec's globs are patterns rather than a
            // partition, so the domain namespace decides which of the
            // candidates is right (technical spec 15A.2) and this asserts the
            // declaration is one of them.
            $this->assertContains(
                $declared->value,
                $expected,
                "[{$route->uri()}] is gated on [{$declared->value}] and data/API 5.9 puts it in ["
                .implode(', ', $expected).'].',
            );
        }

        $this->assertGreaterThan(
            0,
            $matched,
            'No registered route matched any endpoint group in data/API 5.9, so this test proved nothing.',
        );
    }

    public function test_every_gate_names_one_module_from_the_catalogue(): void
    {
        $gated = 0;

        foreach ($this->routes() as $route) {
            $declarations = $this->declarations($route);

            if ($declarations === []) {
                continue;
            }

            $gated++;

            $this->assertCount(
                1,
                $declarations,
                "[{$route->uri()}] declares more than one owning module ["
                .implode(', ', $declarations).']. A route belongs to exactly one (data/API 5.9).',
            );

            $this->assertContains(
                $declarations[0],
                ModuleKey::keys(),
                "[{$route->uri()}] is gated on [{$declarations[0]}], which is not in the catalogue (MOD-003).",
            );
        }

        $this->assertGreaterThan(0, $gated, 'Nothing is gated, so MOD-012 is unenforced.');
    }

    public function test_core_endpoints_that_compose_module_data_carry_no_gate(): void
    {
        foreach (self::NEVER_GATED as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Core route [{$name}] no longer exists.");

            $this->assertNull(
                $this->declaredModule($route),
                "[{$name}] is core and composes module-owned data, so it omits an inactive "
                .'module\'s contribution rather than refusing (MOD-019; data/API 5.9).',
            );
        }
    }

    /**
     * Every command endpoint the gate covers refuses before its handler, which
     * means the gate has to be on the route rather than in the controller.
     * A command that checked module state itself would still have run its
     * validation, its authorization, and possibly its audit entry first
     * (data/API 5.9).
     */
    public function test_gated_commands_declare_their_module_on_the_route(): void
    {
        $commands = array_filter(
            $this->routes(),
            static fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/commands/'),
        );

        $this->assertNotEmpty($commands);

        foreach ($commands as $route) {
            $declarations = $this->declarations($route);

            if ($declarations === []) {
                continue;
            }

            $this->assertContains(
                EnforceActiveModule::for(ModuleKey::from($declarations[0])),
                $route->middleware(),
                "[{$route->uri()}] does not declare its module in the route definition.",
            );
        }
    }

    /**
     * @return list<RoutingRoute>
     */
    private function routes(): array
    {
        return array_values(iterator_to_array(Route::getRoutes()->getIterator()));
    }

    /**
     * The module keys a route's gates name.
     *
     * @return list<string>
     */
    private function declarations(RoutingRoute $route): array
    {
        $keys = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, EnforceActiveModule::class.':')) {
                continue;
            }

            $keys[] = substr($middleware, strlen(EnforceActiveModule::class) + 1);
        }

        return array_values(array_unique($keys));
    }

    private function declaredModule(RoutingRoute $route): ?ModuleKey
    {
        $declarations = $this->declarations($route);

        return $declarations === [] ? null : ModuleKey::tryFrom($declarations[0]);
    }

    /**
     * The modules whose data/API 5.9 patterns this route URI falls under.
     *
     * @param  array<string, list<string>>  $ownership
     * @return list<string>
     */
    private function modulesMatching(string $uri, array $ownership): array
    {
        $matches = [];

        foreach ($ownership as $module => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($this->patternToRegex($pattern), $uri) === 1) {
                    $matches[] = $module;

                    break;
                }
            }
        }

        return $matches;
    }

    private function patternToRegex(string $pattern): string
    {
        $quoted = preg_quote(ltrim($pattern, '/'), '#');

        return '#^'.str_replace('\*', '.*', $quoted).'$#';
    }

    /**
     * Data/API 5.9's module-to-endpoint-group mapping, keyed by module.
     *
     * The block is one module per entry, its path patterns wrapped onto
     * indented continuation lines and comma-separated within a line. A term
     * after a comma inherits the directory of the term before it, which is how
     * the spec writes `/api/commands/*-shift*, *-signup*`.
     *
     * @return array<string, list<string>>
     */
    private function specOwnership(): array
    {
        $spec = file_get_contents(dirname(__DIR__, 4).'/docs/db/meridian-data-model-and-api-specification.md');

        $this->assertIsString($spec, 'The data/API specification could not be read.');

        $start = strpos($spec, '### 5.9 Module Gating');
        $this->assertNotFalse($start, 'The data/API specification no longer contains section 5.9.');

        $end = strpos($spec, 'Gate behavior:', $start);
        $this->assertNotFalse($end, 'Section 5.9 no longer states its gate behavior.');

        preg_match('/```text\n(.*?)```/s', substr($spec, $start, $end - $start), $matches);

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

            $directory = '';

            foreach (explode(',', $terms) as $term) {
                // The spec annotates one entry with the section that defines it
                // (`/api/insights/* (5.8)`); it is prose, not path.
                $term = trim(preg_replace('/\s*\(.*\)\s*$/', '', $term) ?? '');

                if ($term === '') {
                    continue;
                }

                if (! str_starts_with($term, '/')) {
                    $term = $directory.$term;
                } else {
                    $directory = substr($term, 0, (int) strrpos($term, '/') + 1);
                }

                $ownership[$current][] = $term;
            }
        }

        return $ownership;
    }
}
