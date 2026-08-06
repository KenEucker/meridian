<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Audit;

use App\Domain\Audit\AuditActionCatalog;
use App\Domain\Audit\AuditVerbosity;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Orchid\Layouts\Audit\AuditLimitsLayout;
use App\Orchid\Layouts\Audit\AuditOverridesLayout;
use App\Orchid\Layouts\Audit\AuditVerbosityLayout;
use App\Services\Audit\AuditArchivalService;
use App\Services\Audit\AuditPolicy;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * One organization's audit configuration (data/API 14.1; requirements 2.4).
 *
 * Three controls, and the screen keeps them apart because they answer different
 * questions:
 *
 *  - **Level** decides how much is written, on a five-step scale.
 *  - **Exceptions** are the per-action overrides for the operator who wants one
 *    specific thing recorded that their level omits, or omitted that it
 *    includes. Offered as two lists rather than a grid of ninety switches,
 *    because "always these, never these, the level decides the rest" is what an
 *    operator is actually expressing.
 *  - **Limits** decide how much is kept. Reaching one archives the oldest rows
 *    to a file and records that it did; nothing is deleted outright.
 *
 * Required actions are listed and are not editable. Requirements 2.4 and
 * data/API section 8 decide those, and the write path enforces them whatever
 * this screen saves — so showing them as adjustable would be showing a control
 * that does not work.
 *
 * Saving is audited through the ordinary configuration path, which means a
 * change to what gets recorded is itself recorded.
 */
class AuditSettingsEditScreen extends Screen
{
    /**
     * @var Organization
     */
    public $organization;

    /**
     * @return array<string, mixed>
     */
    public function query(Organization $organization, AuditUsage $usage): iterable
    {
        $measured = $usage->forOrganization($organization);
        $overrides = is_array($organization->audit_action_overrides)
            ? $organization->audit_action_overrides
            : [];

        return [
            'organization' => $organization,
            'audit' => [
                'verbosity' => $organization->auditVerbosity()->value,
                'max_rows' => $organization->audit_max_rows,
                'max_bytes' => $organization->audit_max_bytes,
                'retention_days' => $organization->audit_retention_days,
                'always' => array_keys(array_filter($overrides, static fn ($on): bool => (bool) $on)),
                'never' => array_keys(array_filter($overrides, static fn ($on): bool => ! $on)),
            ],
            'usage_rows' => number_format($measured['rows']),
            'usage_bytes' => $measured['bytes'],
            'usage_oldest' => (string) ($measured['oldest_at'] ?? __('None')),
            'required_actions' => implode(', ', AuditActionCatalog::REQUIRED),
            'verbosity_help' => $this->verbosityHelp(),
        ];
    }

    public function name(): ?string
    {
        return 'Audit Settings';
    }

    public function description(): ?string
    {
        return $this->organization->name ?? 'Organization audit configuration';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.audit.settings',
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
                ->route('platform.audit.settings'),

            /*
             * Applying the limits now rather than waiting for the nightly pass.
             * Offered because an operator who has just set a limit wants to see
             * it take effect, and because the alternative is them wondering
             * whether it worked until tomorrow.
             */
            Button::make(__('Apply limits now'))
                ->icon('bs.archive')
                ->confirm(__('Audit history past the configured limits will be written to an archive file and removed from the table. This is recorded and cannot be undone from here.'))
                ->method('applyLimits'),

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
        return [
            Layout::block(AuditVerbosityLayout::class)
                ->title(__('What is recorded'))
                ->description(__('The level decides which entries are written. Required entries are written whatever is set here.')),

            Layout::block(AuditOverridesLayout::class)
                ->title(__('Exceptions'))
                ->description(__('Actions to always record, or never record, regardless of the level. An action named in both lists is recorded — an explicit "always" wins, because losing the record is the worse mistake.')),

            Layout::block(AuditLimitsLayout::class)
                ->title(__('What is kept'))
                ->description(__('Leave a limit empty for no limit. Reaching one archives the oldest entries to a file on the node and records the archival; nothing is deleted outright.')),
        ];
    }

    public function save(
        Request $request,
        Organization $organization,
        AuditService $audit,
        AuditPolicy $policy,
    ): RedirectResponse {
        $validated = $request->validate([
            'audit.verbosity' => ['required', 'string'],
            'audit.max_rows' => ['nullable', 'integer', 'min:1'],
            'audit.max_bytes' => ['nullable', 'integer', 'min:1'],
            'audit.retention_days' => ['nullable', 'integer', 'min:1'],
            'audit.always' => ['nullable', 'array'],
            'audit.always.*' => ['string'],
            'audit.never' => ['nullable', 'array'],
            'audit.never.*' => ['string'],
        ]);

        $values = $validated['audit'];
        $before = $this->snapshot($organization);

        $organization->forceFill([
            'audit_verbosity' => AuditVerbosity::fromValue($values['verbosity'])->value,
            'audit_max_rows' => $values['max_rows'] ?? null,
            'audit_max_bytes' => $values['max_bytes'] ?? null,
            'audit_retention_days' => $values['retention_days'] ?? null,
            'audit_action_overrides' => $this->overrides(
                $values['always'] ?? [],
                $values['never'] ?? [],
            ),
        ])->save();

        // A long-running worker holds the previous configuration in memory, so
        // the change reaches it rather than waiting for the process to end.
        $policy->forget();

        $audit->record(
            action: 'organization.configuration_updated',
            entityType: $organization->getMorphClass(),
            entityId: (string) $organization->getKey(),
            actorUser: $request->user() instanceof User ? $request->user() : null,
            organizationId: (string) $organization->getKey(),
            before: $before,
            after: $this->snapshot($organization->refresh()),
            reason: 'Audit configuration changed from the God Mode console.',
            sourceContext: AuditEvent::SOURCE_ORCHID,
        );

        Toast::info(__('Audit settings were saved.'));

        return redirect()->route('platform.audit.settings.edit', $organization->id);
    }

    public function applyLimits(
        Request $request,
        Organization $organization,
        AuditArchivalService $archival,
    ): RedirectResponse {
        $result = $archival->enforce(
            $organization,
            $request->user() instanceof User ? $request->user() : null,
        );

        Toast::info($result['archived'] === 0
            ? __('Nothing is past the configured limits.')
            : __('Archived :count entries to :file.', [
                'count' => $result['archived'],
                'file' => (string) $result['file'],
            ]));

        return redirect()->route('platform.audit.settings.edit', $organization->id);
    }

    /**
     * The two lists collapsed into the stored `{action: bool}` map.
     *
     * An action in both lists resolves to `true`. Somebody who has said both
     * things has contradicted themselves, and the resolution that keeps the
     * record is the one to prefer.
     *
     * @param  list<string>  $always
     * @param  list<string>  $never
     * @return array<string, bool>|null
     */
    private function overrides(array $always, array $never): ?array
    {
        $overrides = [];

        foreach ($never as $action) {
            $overrides[$action] = false;
        }

        foreach ($always as $action) {
            $overrides[$action] = true;
        }

        // Null rather than an empty array, so "no exceptions" reads the same in
        // the column as it does for an organization that never opened this
        // screen.
        return $overrides === [] ? null : $overrides;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Organization $organization): array
    {
        return [
            'audit_verbosity' => $organization->audit_verbosity,
            'audit_max_rows' => $organization->audit_max_rows,
            'audit_max_bytes' => $organization->audit_max_bytes,
            'audit_retention_days' => $organization->audit_retention_days,
            'audit_action_overrides' => $organization->audit_action_overrides,
        ];
    }

    private function verbosityHelp(): string
    {
        return collect(AuditVerbosity::options())
            ->map(fn (array $option): string => $option['label'].' — '.$option['description'])
            ->implode(' ');
    }
}
