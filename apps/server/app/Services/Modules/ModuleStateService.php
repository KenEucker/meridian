<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Domain\Modules\ModuleKey;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Node\EventAuthorityException;
use Illuminate\Support\Carbon;

/**
 * Reading and changing what an organization is entitled to run (MOD-006,
 * MOD-011; technical spec 15A.3, 15A.6).
 *
 * The single writer of the entitlement half of `organization_modules`. It is a
 * service rather than screen code because three things have to happen together
 * and none of them may be forgotten by a second surface later: the governance
 * rules are asserted (MOD-010), the transition is written, and the transition
 * is audited with previous and new state (MOD-011).
 *
 * **Only what changed is written.** A save that leaves a module where it was
 * writes no row, stamps no timestamp, and records no audit entry, so the trail
 * holds transitions rather than submissions and `entitlement_changed_at` keeps
 * meaning what it says. That also keeps a module nobody has decided about
 * un-stated: {@see ModuleState} distinguishes "entitled because the default is
 * entitled" from "entitled because somebody said so", and materializing a row
 * for every module on the first save would erase that distinction for nothing.
 * M19.19 writes the full set at organization creation, which is where a
 * complete set of rows properly comes from.
 *
 * **Enablement is not written here.** MOD-008 gives that decision to the
 * organization, from the ORG-018 configuration surface (M19.15). Revoking
 * entitlement therefore leaves `enabled` exactly as the organization left it,
 * which is MOD-007's requirement and falls out of never touching the column
 * rather than being separately arranged. A row created by a revoke starts from
 * the un-stated default of enabled, so restoring entitlement restores the
 * organization to running the module — which is the state it was in.
 */
class ModuleStateService
{
    /**
     * MOD-011's audit action. In {@see \App\Domain\Audit\AuditActionCatalog}'s
     * required floor, because MOD-011 audits every transition unconditionally
     * and a verbosity setting must not be able to switch that off.
     */
    public const AUDIT_ENTITLEMENT_CHANGED = 'organization_module.entitlement_changed';

    public function __construct(
        private readonly ModuleGovernance $governance,
        private readonly AuditService $audit,
    ) {}

    /**
     * Every catalogue module's state for this organization, in catalogue order.
     *
     * The catalogue is what is iterated, not the stored rows — MOD-021 requires
     * every module presented for every organization, and an organization with
     * no rows at all is the most common case there is.
     *
     * @return list<ModuleState>
     */
    public function stateFor(Organization $organization): array
    {
        $organizationId = (string) $organization->getKey();

        return $this->stateForAll([$organizationId])[$organizationId];
    }

    /**
     * The same thing for several organizations at once, in one query.
     *
     * The console's list screen renders every organization's state on one page,
     * and asking per row would be three queries per organization for a screen
     * whose whole content is eight booleans each.
     *
     * @param  list<string>  $organizationIds
     * @return array<string, list<ModuleState>>
     */
    public function stateForAll(array $organizationIds): array
    {
        $ids = array_values(array_unique($organizationIds));

        $rows = $ids === []
            ? collect()
            : OrganizationModule::query()
                ->with(['entitlementChangedBy', 'enablementChangedBy'])
                ->whereIn('organization_id', $ids)
                ->get()
                ->groupBy(static fn (OrganizationModule $row): string => (string) $row->organization_id);

        $state = [];

        foreach ($ids as $organizationId) {
            $stated = $rows->get($organizationId, collect())
                ->keyBy(static fn (OrganizationModule $row): string => (string) $row->module_key);

            $state[$organizationId] = array_map(
                static function (ModuleKey $module) use ($stated): ModuleState {
                    $row = $stated->get($module->value);

                    return $row instanceof OrganizationModule
                        ? ModuleState::fromRow($module, $row)
                        : ModuleState::unstated($module);
                },
                ModuleKey::cases(),
            );
        }

        return $state;
    }

    /**
     * Apply a desired entitlement state for one organization.
     *
     * Keyed by module key; a catalogue module the array does not name is left
     * alone, and a key the catalogue does not know is ignored rather than
     * rejected — the catalogue is what this iterates, so a stale key in a
     * submitted form decides nothing.
     *
     * @param  array<string, bool>  $entitlement
     * @return array{granted: list<ModuleKey>, revoked: list<ModuleKey>}
     *
     * @throws ModuleAuthorityException when this node does not own module state
     * @throws EventAuthorityException when the organization is inside its active event window
     */
    public function setEntitlement(
        Organization $organization,
        array $entitlement,
        ?User $actor = null,
        ?string $reason = null,
    ): array {
        $organizationId = (string) $organization->getKey();

        // Asserted before anything is read, so a refusal is a refusal of the
        // whole submission rather than of whichever module happened to be
        // reached first.
        $this->governance->assertEditable($organizationId, (string) $organization->name);

        $granted = [];
        $revoked = [];

        foreach ($this->stateFor($organization) as $state) {
            if (! array_key_exists($state->key(), $entitlement)) {
                continue;
            }

            $desired = (bool) $entitlement[$state->key()];

            if ($desired === $state->entitled) {
                continue;
            }

            $this->writeEntitlement($organization, $state, $desired, $actor, $reason);

            if ($desired) {
                $granted[] = $state->module;
            } else {
                $revoked[] = $state->module;
            }
        }

        return ['granted' => $granted, 'revoked' => $revoked];
    }

    private function writeEntitlement(
        Organization $organization,
        ModuleState $state,
        bool $entitled,
        ?User $actor,
        ?string $reason,
    ): void {
        $row = OrganizationModule::query()->firstOrNew([
            'organization_id' => $organization->getKey(),
            'module_key' => $state->key(),
        ]);

        $row->fill([
            'entitled' => $entitled,
            // MOD-007: the organization's own choice, carried forward untouched
            // — and for a module nobody has written a row for, the un-stated
            // default, which is the choice it is currently running under.
            'enabled' => $state->enabled,
            'entitlement_changed_at' => Carbon::now(),
            'entitlement_changed_by_user_id' => $actor?->getKey(),
        ])->save();

        $this->audit->record(
            action: self::AUDIT_ENTITLEMENT_CHANGED,
            entityType: $row->getMorphClass(),
            entityId: (string) $row->getKey(),
            actorUser: $actor,
            organizationId: (string) $organization->getKey(),
            before: $this->snapshot($state->module, $state->entitled, $state->enabled),
            after: $this->snapshot($state->module, $entitled, $state->enabled),
            reason: $reason,
            sourceContext: AuditEvent::SOURCE_ORCHID,
        );
    }

    /**
     * MOD-011's "previous and new state": both halves and the module, in both
     * snapshots.
     *
     * `enabled` is unchanged by an entitlement transition and is recorded
     * anyway, because active is the pair and a reader of the trail asking
     * whether this change turned the module off needs the other half to answer
     * it. `active` is derived here rather than left to be re-derived by
     * whoever reads the row later.
     *
     * @return array<string, mixed>
     */
    private function snapshot(ModuleKey $module, bool $entitled, bool $enabled): array
    {
        return [
            'module_key' => $module->value,
            'entitled' => $entitled,
            'enabled' => $enabled,
            'active' => $entitled && $enabled,
        ];
    }
}
