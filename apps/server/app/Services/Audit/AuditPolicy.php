<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Domain\Audit\AuditActionCatalog;
use App\Domain\Audit\AuditVerbosity;
use App\Models\Organization;

/**
 * Whether an audit entry is written at all (requirements 2.4; data/API 8, 14.1).
 *
 * Consulted by {@see AuditService} on every write, and by nothing else. The
 * order of the three questions is the whole design:
 *
 *  1. **Is it required?** The floor wins over everything. A configuration that
 *     tried to switch off a credential revocation is ignored rather than
 *     rejected, because the write path is where the obligation lives and a
 *     value could have reached the column from a repair tool that never saw the
 *     configuration surface.
 *  2. **Is there an override for it?** The advanced per-action control, for the
 *     operator who wants one thing recorded that their level omits or one
 *     omitted that it includes.
 *  3. **Does the level include it?** The five-level control, compared against
 *     the level the catalogue puts the action at.
 *
 * Two things are deliberately outside all of it. An entry with no organization
 * is node or system history — pairing, node configuration, device trust — and
 * belongs to whoever runs the node rather than to any organization's
 * preference, so it is always written. And an action the catalogue does not
 * know is always written, because the catalogue cannot be complete by
 * construction and a gap in it must not become missing history.
 */
final class AuditPolicy
{
    /**
     * Organizations already looked up, including the ones that resolved to
     * nothing — `array_key_exists` rather than a null check, so a missing
     * organization is not looked up again on every entry.
     *
     * @var array<string, Organization|null>
     */
    private array $loaded = [];

    public function shouldRecord(?string $organizationId, string $action): bool
    {
        // The floor, checked before anything is loaded: no configuration can
        // reach below it, so there is nothing to read.
        if (AuditActionCatalog::isRequired($action)) {
            return true;
        }

        // Node and system history belongs to the node, not to an organization.
        if ($organizationId === null || $organizationId === '') {
            return true;
        }

        $organization = $this->organization($organizationId);

        if (! $organization instanceof Organization) {
            return true;
        }

        $override = $this->overrideFor($organization, $action);

        if ($override !== null) {
            return $override;
        }

        $level = AuditActionCatalog::levelFor($action);

        // Uncatalogued: written at every level. See the class docblock.
        if ($level === null) {
            return true;
        }

        return $organization->auditVerbosity()->includes($level);
    }

    /**
     * The organization's explicit answer for one action, or null when it has
     * not given one.
     */
    private function overrideFor(Organization $organization, string $action): ?bool
    {
        $overrides = $organization->audit_action_overrides;

        if (! is_array($overrides) || ! array_key_exists($action, $overrides)) {
            return null;
        }

        return (bool) $overrides[$action];
    }

    /**
     * Organizations are loaded once per request rather than once per audit
     * entry. A single import writes thousands of entries for one organization,
     * and reading the configuration row for each of them would make the
     * configuration more expensive than the record it governs.
     */
    private function organization(string $organizationId): ?Organization
    {
        if (! array_key_exists($organizationId, $this->loaded)) {
            $this->loaded[$organizationId] = Organization::query()->find($organizationId);
        }

        return $this->loaded[$organizationId];
    }

    /**
     * Forget what has been loaded.
     *
     * The configuration surface calls this after a save, so a long-running
     * process — a queue worker, an import — picks up a change made while it was
     * running rather than finishing the job under the old setting.
     */
    public function forget(): void
    {
        $this->loaded = [];
    }
}
