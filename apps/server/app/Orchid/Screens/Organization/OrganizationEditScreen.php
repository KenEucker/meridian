<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Organization;

use App\Domain\Staffing\ProfileChangePolicy;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Orchid\Layouts\Organization\OrganizationBrandingLayout;
use App\Orchid\Layouts\Organization\OrganizationEditLayout;
use App\Orchid\Support\BrandingScreenSupport;
use App\Services\Audit\AuditService;
use App\Services\Branding\BrandingAdminService;
use App\Services\Branding\BrandingPalette;
use App\Services\Branding\Lettermark;
use App\Services\Events\IncidentCommandDepartmentSelectionService;
use App\Services\Incidents\IncidentTypeProvisioner;
use App\Services\Node\EventAuthorityException;
use App\Services\Organizations\OrganizationConfigurationException;
use App\Services\Organizations\OrganizationConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class OrganizationEditScreen extends Screen
{
    use BrandingScreenSupport;

    /**
     * @var Organization
     */
    public $organization;

    /**
     * @return array<string, mixed>
     */
    public function query(Organization $organization): iterable
    {
        $branding = $this->brandingState($organization);

        return [
            'organization' => $organization,
            'branding' => $branding,
            'branding_slots' => [
                [
                    'label' => __('Full logo lockup'),
                    'url' => $branding['full_lockup_url'],
                    'lettermark' => Lettermark::forName(
                        $organization->brandingDisplayName() !== '' ? $organization->brandingDisplayName() : 'Meridian',
                    ),
                ],
                [
                    'label' => __('Compact logo mark'),
                    'url' => $branding['compact_mark_url'],
                    'lettermark' => Lettermark::forName(
                        $organization->brandingDisplayName() !== '' ? $organization->brandingDisplayName() : 'Meridian',
                    ),
                ],
            ],
        ];
    }

    /**
     * The branding profile as the form reads it (M15A.6).
     *
     * Palette values fall back to Meridian's defaults rather than to empty
     * inputs, because a colour picker with no value is not a neutral starting
     * point — it is black, and saving it would be a silent choice nobody made.
     *
     * @return array<string, mixed>
     */
    private function brandingState(Organization $organization): array
    {
        return [
            'display_name' => $organization->branding_display_name,
            'department_branding_enabled' => (bool) ($organization->department_branding_enabled ?? true),
            'palette' => BrandingPalette::fromStored($organization->branding_palette_json)->toArray(),
            'full_lockup_url' => $this->brandingAssetUrl($organization->branding_full_lockup_attachment_id),
            'compact_mark_url' => $this->brandingAssetUrl($organization->branding_compact_mark_attachment_id),
        ];
    }

    public function name(): ?string
    {
        return $this->organization->exists ? 'Edit Organization' : 'Create Organization';
    }

    public function description(): ?string
    {
        return 'Organization identity and core configuration.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.organizations',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Cancel'))
                ->icon('bs.x-circle')
                ->route('platform.organizations'),

            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        $layouts = [
            Layout::block(OrganizationEditLayout::class)
                ->title(__('Organization'))
                ->description(__('Organizations produce events, define departments, and manage volunteers.')),
        ];

        // Branding needs an organization to belong to, and the logo upload
        // needs somewhere to attach. Both arrive with the first save.
        if ($this->organization->exists) {
            $layouts[] = Layout::view('orchid.branding.assets');
            $layouts[] = Layout::block(OrganizationBrandingLayout::class)
                ->title(__('Branding'))
                ->description($this->brandingDescription());
        }

        return $layouts;
    }

    private function brandingDescription(): string
    {
        $lock = $this->brandingLockReason((string) $this->organization->getKey());

        $base = __(
            'Display name, logo assets, and the ten settable colors. Organizers normally edit this on the product '
            .'branding surface, which previews the result before saving; this console shows and repairs the stored '
            .'values. Both are validated identically.'
        );

        return $lock === null ? $base : $base.' '.$lock;
    }

    public function save(Request $request, Organization $organization): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'organization.name' => ['required', 'string', 'max:255'],
            'organization.slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique(Organization::class, 'slug')->ignore($organization),
            ],
            'organization.default_ic_department_id' => ['nullable', 'uuid', Rule::exists(Department::class, 'id')],
            'organization.active_inactive_threshold_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'organization.prospective_inactive_threshold_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'organization.calendar_year_start_month' => ['nullable', 'integer', 'between:1,12'],
            'organization.calendar_year_start_day' => ['nullable', 'integer', 'between:1,31'],
            'organization.hours_correction_grace_period_days' => ['nullable', 'integer', 'between:0,365'],
            'organization.default_credit_policy_id' => ['nullable', 'uuid'],
            'organization.organizers_department_id' => ['nullable', 'uuid'],
            'organization.default_placement_department_id' => ['nullable', 'uuid'],
            'organization.handle_change_policy' => ['nullable', 'string', Rule::in(ProfileChangePolicy::values())],
            'organization.profile_picture_change_policy' => ['nullable', 'string', Rule::in(ProfileChangePolicy::values())],
            'organization.handle_self_service_change_limit' => ['nullable', 'integer', 'between:0,50'],
            'organization.notifications_suppressed' => ['nullable', 'boolean'],
            'branding.display_name' => ['nullable', 'string', 'max:255'],
            'branding.department_branding_enabled' => ['nullable', 'boolean'],
            'branding.palette' => ['nullable', 'array'],
            'branding.palette.*' => ['nullable', 'string', 'max:32'],
        ]);

        $validator->after(function ($validator) use ($request, $organization): void {
            $departmentId = $request->input('organization.default_ic_department_id');

            if (blank($departmentId)) {
                return;
            }

            if (! $organization->exists) {
                $validator->errors()->add(
                    'organization.default_ic_department_id',
                    'Default Incident Command department can only be selected after the organization exists.'
                );

                return;
            }

            $isValidDepartment = Department::query()
                ->active()
                ->whereKey($departmentId)
                ->where('organization_id', $organization->id)
                ->exists();

            if (! $isValidDepartment) {
                $validator->errors()->add(
                    'organization.default_ic_department_id',
                    'Default Incident Command department must be an active department in this organization.'
                );
            }
        });

        /** @var array{organization: array<string, mixed>} $validated */
        $validated = $validator->validate();
        $attributes = $validated['organization'];
        $defaultIcDepartmentId = $attributes['default_ic_department_id'] ?? null;
        unset($attributes['default_ic_department_id']);

        /*
         * The M18.14 configuration fields split off and route through
         * OrganizationConfigurationService below, so the Orchid path and the
         * organizer product surface write the same audit rows and answer to
         * the same governance — the ORG-021 active-window freeze included.
         * Identity (name, slug) stays a direct fill: it predates the
         * configuration record and is not part of it.
         */
        $configuration = [];
        foreach ([
            'active_inactive_threshold_years',
            'prospective_inactive_threshold_years',
            'calendar_year_start_month',
            'calendar_year_start_day',
            'default_credit_policy_id',
            'organizers_department_id',
            'default_placement_department_id',
            'handle_change_policy',
            'profile_picture_change_policy',
            'handle_self_service_change_limit',
        ] as $key) {
            if (array_key_exists($key, $attributes)) {
                $configuration[$key] = blank($attributes[$key]) ? null : $attributes[$key];
            }

            unset($attributes[$key]);
        }

        // The grace period cannot be cleared (ORG-017: an organization always
        // has one), so a blank input means "leave it alone", not null.
        if (filled($attributes['hours_correction_grace_period_days'] ?? null)) {
            $configuration['hours_correction_grace_period_days'] = $attributes['hours_correction_grace_period_days'];
        }
        unset($attributes['hours_correction_grace_period_days']);

        /*
         * The NOTIFY-009 suppression switch is a boolean on the form and a
         * timestamp in the column, because "suppressed since" is the fact an
         * operator wants and a checkbox has nowhere to keep it. Switching it on
         * again while it is already on must not move the timestamp, or the
         * record of when sending actually stopped is lost on every save.
         */
        $suppressNotifications = (bool) ($attributes['notifications_suppressed'] ?? false);
        unset($attributes['notifications_suppressed']);

        $wasNew = ! $organization->exists;

        $organization->fill($attributes)->save();

        $this->applyNotificationSuppression($organization, $suppressNotifications, $request);

        /*
         * Only values that actually moved go to the service: an unchanged save
         * must not trip the ORG-021 governance freeze on its way to the
         * branding block below, and the service's own audit entry should exist
         * only when configuration changed.
         */
        $configuration = array_filter(
            $configuration,
            function (mixed $value, string $key) use ($organization): bool {
                $current = $key === 'hours_correction_grace_period_days'
                    ? $organization->hoursCorrectionGracePeriodDays()
                    : $organization->getAttribute($key);

                $normalize = fn (mixed $side): ?string => $side === null ? null : (string) $side;

                return $normalize($value) !== $normalize($current);
            },
            ARRAY_FILTER_USE_BOTH,
        );

        if ($configuration !== []) {
            try {
                app(OrganizationConfigurationService::class)->update(
                    $organization,
                    $configuration,
                    $this->configurationActor($request),
                    AuditEvent::SOURCE_ORCHID,
                );
            } catch (EventAuthorityException|OrganizationConfigurationException $exception) {
                throw ValidationException::withMessages([
                    'organization.hours_correction_grace_period_days' => $exception->getMessage(),
                ]);
            }
        }

        // A new organization starts with the default incident types, so its
        // first incident has something to choose from. Only on creation: these
        // are a starting point, and re-adding them on every save would undo an
        // organization's curation of its own list.
        if ($wasNew) {
            app(IncidentTypeProvisioner::class)->ensureDefaults($organization);
        }

        $department = filled($defaultIcDepartmentId)
            ? Department::query()->findOrFail($defaultIcDepartmentId)
            : null;

        try {
            app(IncidentCommandDepartmentSelectionService::class)->configureOrganizationDefault(
                organization: $organization,
                department: $department,
                actor: $request->user() instanceof User ? $request->user() : null,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'organization.default_ic_department_id' => $exception->getMessage(),
            ]);
        }

        $this->saveBranding($request, $organization);

        Toast::info(__('Organization was saved.'));

        return redirect()->route('platform.organizations');
    }

    /**
     * Switch this organization's notification email off or back on
     * (NOTIFY-009), and audit the change.
     *
     * Audited because it is a governance decision with a visible consequence
     * for every member of the organization — nobody hears about an approval,
     * an addition, or a cancellation while it is on — and because the God Mode
     * readiness surface reports the state without recording who chose it.
     */
    private function applyNotificationSuppression(
        Organization $organization,
        bool $suppress,
        Request $request,
    ): void {
        $wasSuppressed = $organization->notificationsSuppressed();

        if ($wasSuppressed === $suppress) {
            return;
        }

        $organization->forceFill([
            'notifications_suppressed_at' => $suppress ? now() : null,
        ])->save();

        app(AuditService::class)->recordForEntity(
            entity: $organization,
            action: $suppress
                ? 'organization.notifications_suppressed'
                : 'organization.notifications_resumed',
            actorUser: $request->user() instanceof User ? $request->user() : null,
            organizationId: (string) $organization->getKey(),
            before: ['notifications_suppressed' => $wasSuppressed],
            after: ['notifications_suppressed' => $suppress],
            sourceContext: AuditEvent::SOURCE_ORCHID,
        );
    }

    private function configurationActor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Apply the branding block through the same services the product path uses.
     *
     * Orchid gets no shortcut: the contrast validator, the central-node
     * authority rule, and the active-event freeze all apply, and a refusal
     * comes back as a field error rather than as a saved-anyway toast.
     */
    private function saveBranding(Request $request, Organization $organization): void
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $organization->exists) {
            return;
        }

        $palette = $request->input('branding.palette');
        $palette = is_array($palette) && $palette !== [] ? $palette : null;

        // Saving this screen without touching the palette must not turn an
        // unbranded organization into a branded one. The colour inputs always
        // post *something* — Meridian's defaults when nothing is stored — so
        // an unchanged default palette on an organization that has none is
        // treated as "no palette submitted" rather than as a choice.
        if (
            $palette !== null
            && $organization->branding_palette_json === null
            && $palette === BrandingPalette::meridianDefault()->toArray()
        ) {
            $palette = null;
        }

        $this->guardBranding(
            fn () => app(BrandingAdminService::class)->updateOrganizationBranding(
                $organization,
                [
                    'display_name' => $request->input('branding.display_name'),
                    'palette' => $palette,
                    'department_branding_enabled' => $request->boolean('branding.department_branding_enabled'),
                ],
                $actor,
                AuditEvent::SOURCE_ORCHID,
            ),
            'branding.palette',
        );

        $this->applyBrandingAsset(
            $request,
            $organization,
            Attachment::BRANDING_SLOT_FULL_LOCKUP,
            'branding.full_lockup',
            'branding.remove_full_lockup',
        );

        $this->applyBrandingAsset(
            $request,
            $organization,
            Attachment::BRANDING_SLOT_COMPACT_MARK,
            'branding.compact_mark',
            'branding.remove_compact_mark',
        );
    }
}
