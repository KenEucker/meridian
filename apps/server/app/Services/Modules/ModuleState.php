<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Domain\Modules\ModuleKey;
use App\Models\OrganizationModule;
use Illuminate\Support\Carbon;

/**
 * One organization's state for one catalogue module, as a surface has to show
 * it (MOD-005, MOD-021).
 *
 * This exists because a console screen cannot be written against
 * `organization_modules` rows alone. MOD-021 requires every module presented
 * for every organization, and a module with no row is entitled and enabled —
 * so what a screen iterates is the catalogue, with the stored row filled in
 * where there is one. {@see ModuleStateService::stateFor()} builds exactly
 * that: eight of these, always, in catalogue order.
 *
 * `stated` is the difference between "nobody has decided" and "somebody decided
 * yes". Both read as entitled and enabled and both are correct to act on, but
 * only one of them is a decision anybody made, and an operator looking at a
 * screen deserves to be able to tell.
 *
 * It is a read model. It never writes, and the booleans on it are what was true
 * when it was built.
 */
final class ModuleState
{
    public function __construct(
        public readonly ModuleKey $module,
        public readonly bool $entitled,
        public readonly bool $enabled,
        public readonly bool $stated,
        public readonly ?Carbon $entitlementChangedAt = null,
        public readonly ?string $entitlementChangedBy = null,
        public readonly ?Carbon $enablementChangedAt = null,
        public readonly ?string $enablementChangedBy = null,
    ) {}

    /**
     * The MOD-009 default: a module nobody has written a row for is entitled
     * and enabled.
     */
    public static function unstated(ModuleKey $module): self
    {
        return new self(
            module: $module,
            entitled: true,
            enabled: true,
            stated: false,
        );
    }

    public static function fromRow(ModuleKey $module, OrganizationModule $row): self
    {
        return new self(
            module: $module,
            entitled: (bool) $row->entitled,
            enabled: (bool) $row->enabled,
            stated: true,
            entitlementChangedAt: $row->entitlement_changed_at,
            entitlementChangedBy: $row->entitlementChangedBy?->name,
            enablementChangedAt: $row->enablement_changed_at,
            enablementChangedBy: $row->enablementChangedBy?->name,
        );
    }

    public function key(): string
    {
        return $this->module->value;
    }

    /**
     * MOD-022: the module's own name, never its key.
     */
    public function label(): string
    {
        return $this->module->label();
    }

    public function isActive(): bool
    {
        return $this->entitled && $this->enabled;
    }

    /**
     * Why this module is or is not running, in one phrase.
     *
     * Inactive has two causes and they belong to different people, so the
     * phrase names which one: an operator reading "not entitled" knows the
     * control on this screen is the one that fixes it, and one reading
     * "the organization has it turned off" knows it is not.
     */
    public function statusLabel(): string
    {
        if ($this->isActive()) {
            return __('Active');
        }

        if (! $this->entitled && ! $this->enabled) {
            return __('Not entitled, and the organization has it turned off');
        }

        return $this->entitled
            ? __('Entitled, but the organization has it turned off')
            : __('Not entitled');
    }

    /**
     * The last recorded entitlement transition, for display beside the control
     * that makes the next one. The audit trail is the record; this is the
     * shortcut that saves an operator opening it to answer "did somebody
     * already do this".
     */
    public function entitlementHistory(): string
    {
        if ($this->entitlementChangedAt === null) {
            return $this->stated
                ? __('Set when the organization was created.')
                : __('Never set. Entitled by default.');
        }

        return __('Last changed :when by :who.', [
            'when' => $this->entitlementChangedAt->toDayDateTimeString(),
            'who' => $this->entitlementChangedBy ?? __('an unrecorded actor'),
        ]);
    }
}
