<?php

declare(strict_types=1);

namespace App\Orchid\Screens\FieldReport;

use App\Models\FieldReport;
use App\Models\FieldReportAppend;
use App\Models\User;
use App\Orchid\Layouts\FieldReport\FieldReportDetailLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * One Field Report, in full (M18.34; UI contract 12.9; FR-004 through FR-009).
 *
 * The console permission opens the screen; {@see \App\Policies\FieldReportPolicy}
 * decides whether this report is on it, through the same `view` ability the
 * product read goes through. Enforcing it here rather than only in the list is
 * the point: a list filter that a typed URL walks around is not a rule.
 *
 * Appends are shown with the original because FR-008 makes them part of the
 * report rather than commentary on it, and a repair question about a report is
 * usually a question about its corrections.
 *
 * Photos are counted and not served. Downloading one answers to
 * `field_reports.download_photo` rather than to console access (FR-012), and
 * technical spec 22.3 rules out attachment redaction and deletion in Alpha 1,
 * so there is nothing this screen could offer a photo for except to disclose
 * it.
 */
class FieldReportDetailScreen extends Screen
{
    /**
     * @var FieldReport
     */
    public $report;

    /**
     * @return array<string, mixed>
     */
    public function query(Request $request, FieldReport $fieldReport): iterable
    {
        /** @var User $user */
        $user = $request->user();

        Gate::forUser($user)->authorize('view', $fieldReport);

        $fieldReport->load([
            'event',
            'department',
            'team',
            'staff.users',
            'submittedByUser',
            'originDevice',
            'originNode',
            'appends.appendedByUser',
            'attachments',
            'incidentLinks.incident',
        ]);

        return [
            'report' => $fieldReport,
            'event_display' => $fieldReport->event?->name ?? __('None recorded'),
            'department_display' => $fieldReport->department?->name ?? __('None recorded'),
            'team_display' => $fieldReport->team?->name ?? __('None recorded'),
            'author_display' => $fieldReport->staff?->displayName() ?? __('None recorded'),
            'submitted_by_display' => $this->describeSubmitter($fieldReport),
            'device_submitted_display' => $fieldReport->device_submitted_at?->toDayDateTimeString()
                ?? __('None recorded'),
            'server_received_display' => $fieldReport->server_received_at?->toDayDateTimeString()
                ?? __('None recorded'),
            'origin_device_display' => $fieldReport->originDevice?->device_label ?? __('None recorded'),
            'origin_node_display' => $fieldReport->originNode?->node_name ?? __('None recorded'),
            'appends_display' => $this->describeAppends($fieldReport),
            'photos_display' => $this->describePhotos($fieldReport),
            'incidents_display' => $this->describeIncidents($fieldReport),
        ];
    }

    public function name(): ?string
    {
        return 'Field Report';
    }

    public function description(): ?string
    {
        return 'One submitted Field Report with its appends. The original title and body never change after submission, and God Mode does not edit, append to, or redact one.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.field-reports',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Back to Field Reports'))
                ->icon('bs.arrow-left')
                ->route('platform.field-reports'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            FieldReportDetailLayout::class,
        ];
    }

    /**
     * Who typed the report in, said only where that is somebody other than its
     * author (FR-015).
     */
    private function describeSubmitter(FieldReport $report): string
    {
        if (! $report->wasTakenOnBehalf()) {
            return __('The author filed it themselves.');
        }

        return $report->submittedByUser?->name ?? __('Unknown');
    }

    private function describeAppends(FieldReport $report): string
    {
        if ($report->appends->isEmpty()) {
            return __('None.');
        }

        return $report->appends
            ->map(function (FieldReportAppend $append): string {
                $when = $append->device_submitted_at?->toDayDateTimeString()
                    ?? $append->created_at?->toDayDateTimeString()
                    ?? __('Undated');
                $who = $append->appendedByUser?->name ?? __('Unknown');

                return "[{$when}] {$who}\n{$append->body}";
            })
            ->implode("\n\n");
    }

    /**
     * A count and no filenames. FR-012 puts photo access behind its own
     * capability, and a filename is content.
     */
    private function describePhotos(FieldReport $report): string
    {
        $count = $report->attachments->count();

        if ($count === 0) {
            return __('None.');
        }

        return trans_choice(
            '{1} One photo is attached. Photos are not served from the console.'
                .'|[2,*] :count photos are attached. Photos are not served from the console.',
            $count,
            ['count' => $count],
        );
    }

    private function describeIncidents(FieldReport $report): string
    {
        if ($report->incidentLinks->isEmpty()) {
            return __('None.');
        }

        return $report->incidentLinks
            ->map(fn ($link): string => (string) ($link->incident?->incident_number ?? $link->incident_id))
            ->implode(', ');
    }
}
