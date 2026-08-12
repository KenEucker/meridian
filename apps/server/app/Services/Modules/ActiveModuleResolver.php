<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Domain\Modules\ModuleKey;
use App\Models\OrganizationModule;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use WeakReference;

/**
 * The modules an organization is currently running (MOD-005, MOD-016).
 *
 * A module is *active* only when the platform entitles the organization to it
 * and the organization has enabled it. Both halves live on
 * `organization_modules`, and this class is the single seam that reads them: no
 * caller anywhere in the product asks the table what an organization runs, it
 * asks this.
 *
 * A module with no row is entitled and enabled. That is MOD-009's default and
 * the state every organization created before the table existed is in, and it
 * is the safe direction — the failure mode of a row nobody wrote is capability
 * that stays reachable, not capability that silently disappears. M19.19 writes
 * rows at organization creation and backfills the existing ones, which makes
 * the missing-row answer a safety net rather than a normal state.
 *
 * A row naming a module this build's catalogue does not list is ignored, so a
 * catalogue that shrinks in a later build does not turn old rows into answers
 * about modules the code no longer implements (MOD-003).
 *
 * The organization is passed by id rather than as a model because the callers
 * span several: the offline read set reaches departments in more than one
 * organization, and each answers this question for itself.
 */
class ActiveModuleResolver
{
    /**
     * Answers already given, for the request they were given in.
     *
     * The read set asks about several organizations in one pass and the route
     * gate (M19.12) asks about one organization repeatedly within a request, so
     * memoizing keeps both to one query each.
     *
     * The memo is scoped to a request rather than to this object, because the
     * two are not the same lifetime and assuming they were is how a module
     * toggle goes unnoticed. A resolver injected into a service that is itself
     * held by something long-lived — Laravel caches a controller instance on the
     * `Route` that dispatched to it, and a queued worker keeps a container alive
     * across jobs — would otherwise answer a later request from an earlier one's
     * reading, and an organization that switched a module off would keep getting
     * it until the process restarted. {@see self::currentScope()}.
     *
     * @var array<string, list<ModuleKey>>
     */
    private array $resolved = [];

    /**
     * Which request the memo above belongs to, or null outside one.
     */
    private ?WeakReference $memoScope = null;

    /**
     * The modules active for this organization.
     *
     * @return list<ModuleKey>
     */
    public function activeFor(string $organizationId): array
    {
        $this->discardAnswersFromAnotherRequest();

        return $this->resolved[$organizationId] ??= $this->read($organizationId);
    }

    /**
     * Whether one module is active for this organization.
     */
    public function isActive(string $organizationId, ModuleKey $module): bool
    {
        return in_array($module, $this->activeFor($organizationId), true);
    }

    /**
     * The modules active for *every* one of these organizations.
     *
     * The intersection rather than the union, because a section of the read set
     * carries rows from more than one organization at a time and a record must
     * not travel on the strength of a different organization running the module
     * that owns it. Sections are narrowed per organization before this is
     * consulted, so the intersection is what remains to state about the set as
     * a whole.
     *
     * @param  list<string>  $organizationIds
     * @return list<ModuleKey>
     */
    public function activeForAll(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $active = ModuleKey::cases();

        foreach (array_unique($organizationIds) as $organizationId) {
            $forOrganization = $this->activeFor($organizationId);

            $active = array_values(array_filter(
                $active,
                static fn (ModuleKey $module): bool => in_array($module, $forOrganization, true),
            ));
        }

        return $active;
    }

    /**
     * Forget what has been read, so a caller that has just changed module state
     * sees its own change.
     */
    public function forget(?string $organizationId = null): void
    {
        if ($organizationId === null) {
            $current = $this->currentScope();

            $this->resolved = [];
            $this->memoScope = $current === null ? null : WeakReference::create($current);

            return;
        }

        unset($this->resolved[$organizationId]);
    }

    /**
     * Drop the memo when it was filled during a different request.
     *
     * Cheap — one identity comparison per lookup — and it makes the memo mean
     * "for this request" wherever the resolver happens to be held. Outside a
     * request there is no scope to change, so a console command or a test
     * driving the resolver directly keeps the plain per-instance behaviour and
     * can still {@see self::forget()} after writing module state.
     */
    private function discardAnswersFromAnotherRequest(): void
    {
        $current = $this->currentScope();

        if ($current !== null && $this->memoScope?->get() === $current) {
            return;
        }

        $this->resolved = [];
        $this->memoScope = $current === null ? null : WeakReference::create($current);
    }

    /**
     * The request this lookup is happening inside, or null outside one.
     *
     * Held weakly and compared by identity rather than by an object id, because
     * an id is reused once its object is collected — the request the memo was
     * filled for is exactly the object that gets freed between requests, and a
     * reused id would silently keep a stale answer. A weak reference to a
     * collected request reads null and differs from everything, which fails in
     * the direction of one extra query.
     */
    private function currentScope(): ?Request
    {
        $container = Container::getInstance();
        $request = $container->bound('request') ? $container->make('request') : null;

        return $request instanceof Request ? $request : null;
    }

    /**
     * @return list<ModuleKey>
     */
    private function read(string $organizationId): array
    {
        /** @var array<string, bool> $stated */
        $stated = OrganizationModule::query()
            ->where('organization_id', $organizationId)
            ->get(['module_key', 'entitled', 'enabled'])
            ->reduce(static function (array $carry, OrganizationModule $row): array {
                $module = $row->module();

                if ($module !== null) {
                    $carry[$module->value] = $row->isActive();
                }

                return $carry;
            }, []);

        return array_values(array_filter(
            ModuleKey::cases(),
            static fn (ModuleKey $module): bool => $stated[$module->value] ?? true,
        ));
    }
}
