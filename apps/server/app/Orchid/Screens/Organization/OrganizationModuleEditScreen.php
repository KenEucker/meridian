<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Organization;

use App\Domain\Modules\ModuleKey;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Orchid\Layouts\Organization\OrganizationModuleEntitlementLayout;
use App\Services\Modules\ModuleAuthorityException;
use App\Services\Modules\ModuleGovernance;
use App\Services\Modules\ModuleState;
use App\Services\Modules\ModuleStateService;
use App\Services\Node\EventAuthorityException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * One organization's module entitlement (MOD-006, MOD-011, MOD-021; technical
 * spec 15A.3, 15A.6, 22.2).
 *
 * Entitlement is the platform's half of module state: whether Meridian makes a
 * capability available to this organization at all. The organization's half —
 * whether it uses what it has been given — is set from the ORG-018
 * configuration surface under `organization.configuration.manage`, and is shown
 * here read-only so an operator can tell the two apart. Active is both.
 *
 * **Every module, always.** MOD-021 is the reason, and it is a real one: an
 * organization with everything switched off is exactly the organization
 * somebody has come here to fix, and a screen that hid what was off would have
 * nothing on it.
 *
 * **Every transition is audited.** MOD-011 wants the module, the previous and
 * new state, the actor, and the reason where one is given, and
 * {@see ModuleStateService} writes all five for each module that actually
 * moves. A save that changes nothing writes nothing.
 *
 * **The freeze applies to God Mode too.** Module state is governance data, so
 * MOD-010 blocks changes while the organization is inside an active event
 * window, on the same rule as organization configuration. The controls are
 * disabled and the block says which event is holding them rather than letting
 * an operator find out by having a save refused — but the refusal is in the
 * service, not the screen, so the disabled control is a courtesy and not the
 * enforcement.
 */
class OrganizationModuleEditScreen extends Screen
{
    /**
     * @var Organization
     */
    public $organization;

    /**
     * @var bool
     */
    public $editable = true;

    /**
     * @var string
     */
    public $governance_notice = '';

    /**
     * @return array<string, mixed>
     */
    public function query(
        Organization $organization,
        ModuleStateService $modules,
        ModuleGovernance $governance,
    ): iterable {
        $state = $modules->stateFor($organization);

        return [
            'organization' => $organization,
            'module_state' => $state,
            'modules' => $this->formValues($state),
            'editable' => $governance->isEditable((string) $organization->getKey()),
            'governance_notice' => $this->governanceNotice($organization, $governance),
        ];
    }

    public function name(): ?string
    {
        return 'Organization Modules';
    }

    public function description(): ?string
    {
        return $this->organization->name ?? 'Module entitlement';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.organization-modules',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('All organizations'))
                ->icon('bs.arrow-left')
                ->route('platform.organization-modules'),

            Button::make(__('Save entitlement'))
                ->icon('bs.check-circle')
                ->method('save')
                ->canSee((bool) $this->editable),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(OrganizationModuleEntitlementLayout::class)
                ->title(__('Entitlement'))
                ->description($this->governance_notice),
        ];
    }

    public function save(
        Request $request,
        Organization $organization,
        ModuleStateService $modules,
    ): RedirectResponse {
        $validated = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*.entitled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $reason = trim((string) ($validated['reason'] ?? ''));

        try {
            $changed = $modules->setEntitlement(
                organization: $organization,
                entitlement: $this->submittedEntitlement($validated['modules'] ?? []),
                actor: $request->user() instanceof User ? $request->user() : null,
                reason: $reason === '' ? null : $reason,
            );
        } catch (ModuleAuthorityException|EventAuthorityException $exception) {
            Toast::warning(__($exception->getMessage()));

            return redirect()->route('platform.organization-modules.edit', $organization->id);
        }

        Toast::info($this->outcome($changed));

        return redirect()->route('platform.organization-modules.edit', $organization->id);
    }

    /**
     * The submitted form collapsed to the `{module key: entitled}` shape the
     * service applies.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, bool>
     */
    private function submittedEntitlement(array $submitted): array
    {
        $entitlement = [];

        foreach ($submitted as $key => $values) {
            if (is_array($values) && array_key_exists('entitled', $values)) {
                $entitlement[(string) $key] = filter_var(
                    $values['entitled'],
                    FILTER_VALIDATE_BOOL,
                );
            }
        }

        return $entitlement;
    }

    /**
     * @param  list<ModuleState>  $state
     * @return array<string, array{entitled: bool}>
     */
    private function formValues(array $state): array
    {
        $values = [];

        foreach ($state as $module) {
            $values[$module->key()] = ['entitled' => $module->entitled];
        }

        return $values;
    }

    /**
     * @param  array{granted: list<ModuleKey>, revoked: list<ModuleKey>}  $changed
     */
    private function outcome(array $changed): string
    {
        $parts = [];

        if ($changed['granted'] !== []) {
            $parts[] = __('Entitled :modules.', ['modules' => $this->names($changed['granted'])]);
        }

        if ($changed['revoked'] !== []) {
            $parts[] = __('Revoked :modules.', ['modules' => $this->names($changed['revoked'])]);
        }

        return $parts === []
            ? __('Nothing changed, so nothing was recorded.')
            : implode(' ', $parts);
    }

    /**
     * @param  list<ModuleKey>  $modules
     */
    private function names(array $modules): string
    {
        return implode(', ', array_map(
            static fn (ModuleKey $module): string => $module->label(),
            $modules,
        ));
    }

    private function governanceNotice(Organization $organization, ModuleGovernance $governance): string
    {
        $standing = __(
            'Entitlement is what the platform makes available. Whether an entitled module is actually used is the '
            .'organization\'s own decision, set from its configuration surface and shown here for context.'
        );

        if (! $governance->holdsModuleAuthority()) {
            return $standing.' '.__(
                'This node does not hold module state authority, so entitlement is read-only here. Make the change on '
                .'the central node; it syncs down from there.'
            );
        }

        $event = $governance->activeEventFor((string) $organization->getKey());

        if ($event instanceof Event) {
            return $standing.' '.__(
                'Entitlement is frozen while ":event" is in its active event window and cannot be changed on any node '
                .'until it ends. Deactivating a module mid-event would take its records off devices that are already '
                .'holding them.',
                ['event' => (string) $event->name],
            );
        }

        return $standing;
    }
}
