<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\FieldReport;
use App\Models\FieldReportAppend;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\FieldReports\FieldReportPhotoLimits;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The regular-staff cache list of technical spec 9.3, composed for one caller.
 *
 * "Their own shifts. Their department/team info. Field report form. Basic event
 * info. Their own submitted field reports. Published policies/procedures visible
 * to them. Published fragments referenced by those visible documents. Their
 * policy/procedure acknowledgment status. Notes they authored, and
 * Briefing-included Note presentations for events they can access. Relevant
 * readiness/sync state."
 *
 * Every section here is scoped by the caller's own memberships, which is what
 * "their own" means for each of those lines. Nothing in this list is reached
 * through a role: a staff member holds their department's published documents
 * by belonging to the department, and the additive sections a role unlocks are
 * M18.47's. That is why this contributor consults the scope's associations and
 * not its role codes.
 *
 * Two things are deliberately narrower than the surfaces that read the same
 * records online:
 *
 * - Staff rows carry name and handle only. The M8.2 exclusions — no email,
 *   phone, date of birth, emergency contacts, status reasons, or profile
 *   picture storage metadata — apply to the caller's own record too. Nothing
 *   offline needs them, `staff.me` serves them online under its own rules
 *   (M18.20), and a set that carried them for one staff member would be one
 *   change away from carrying them for a roster.
 * - Document visibility is published-and-in-audience only. A maintainer also
 *   reads drafts of documents they maintain
 *   ({@see \App\Services\Documents\DocumentProductAccess}), and that is the
 *   department-lead line of 9.3 — "draft, published, and archived documents
 *   they are allowed to maintain" — which M18.47 adds. Neither list may exceed
 *   what its reader could retrieve through the API; this one is the narrower.
 */
final class RegularStaffSections implements OfflineReadSetContributor
{
    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array
    {
        if (! $scope->hasStaffProfile()) {
            /*
             * A login with no staff profile receives nothing — not an empty
             * shell of every section, which would be a set the client stores
             * and refreshes forever. The form is not an exception: it is only
             * useful to somebody who may file a report.
             */
            return [];
        }

        $documents = $this->visibleDocuments($scope);

        return [
            ...$this->associationSections($scope),
            ...$this->schedulingSections($scope),
            ...$this->documentSections($scope, $documents),
            ...$this->fieldReportSections($scope),
        ];
    }

    /**
     * @return list<DeferredOfflineReadSetSection>
     */
    public function deferredFor(OfflineReadSetScope $scope): array
    {
        return [
            new DeferredOfflineReadSetSection(
                name: 'notes',
                reason: 'Notes have no table on this build, so a device holds none rather than holding an empty list.',
                owningWork: 'Milestone 15: Notes and The Briefing',
                module: ModuleKey::Briefing,
            ),
            new DeferredOfflineReadSetSection(
                name: 'briefing_note_presentations',
                reason: 'The Briefing has no note inclusions on this build, so there is no presentation to carry.',
                owningWork: 'Milestone 15: Notes and The Briefing',
                module: ModuleKey::Briefing,
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Who the caller is, and what they belong to (core)
    |--------------------------------------------------------------------------
    |
    | "Their department/team info" and "basic event info". These are core under
    | MOD-004 and travel whatever modules an organization runs: an organization
    | with every module inactive still has departments, teams, and events.
    */

    /**
     * @return list<OfflineReadSetSection>
     */
    private function associationSections(OfflineReadSetScope $scope): array
    {
        return [
            OfflineReadSetSection::core('staff', $this->rows(
                Staff::query()->whereKey($scope->staffIds)->orderBy('id'),
                fn (Staff $staff): array => [
                    'id' => (string) $staff->getKey(),
                    'legal_name' => $staff->legal_name,
                    'preferred_name' => $staff->preferred_name,
                    'handle' => $staff->handle,
                    'archived_at' => $this->moment($staff->archived_at),
                ],
            )),

            OfflineReadSetSection::core('staff_organization_statuses', $this->rows(
                StaffOrganizationStatus::query()
                    ->whereIn('staff_id', $scope->staffIds)
                    ->orderBy('id'),
                fn (StaffOrganizationStatus $status): array => [
                    'id' => (string) $status->getKey(),
                    'organization_id' => (string) $status->organization_id,
                    'staff_id' => (string) $status->staff_id,
                    'status' => $status->status,
                    // `status_reason` is excluded: it is the organization's
                    // note about a person, not the person's own standing.
                    'status_changed_at' => $this->moment($status->status_changed_at),
                ],
            )),

            OfflineReadSetSection::core('organizations', $this->rows(
                Organization::query()->whereKey($scope->organizationIds)->orderBy('name'),
                fn (Organization $organization): array => [
                    'id' => (string) $organization->getKey(),
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'archived_at' => $this->moment($organization->archived_at),
                ],
            )),

            OfflineReadSetSection::core('departments', $this->rows(
                Department::query()->whereKey($scope->departmentIds)->orderBy('name'),
                fn (Department $department): array => [
                    'id' => (string) $department->getKey(),
                    'organization_id' => (string) $department->organization_id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'description' => $department->description,
                    'default_team_id' => $department->default_team_id === null
                        ? null
                        : (string) $department->default_team_id,
                    'archived_at' => $this->moment($department->archived_at),
                ],
            )),

            OfflineReadSetSection::core('teams', $this->rows(
                Team::query()->whereKey($scope->teamIds)->orderBy('name'),
                fn (Team $team): array => [
                    'id' => (string) $team->getKey(),
                    'department_id' => (string) $team->department_id,
                    'name' => $team->name,
                    'code' => $team->code,
                    'description' => $team->description,
                    'is_default' => (bool) $team->is_default,
                    'archived_at' => $this->moment($team->archived_at),
                ],
            )),

            OfflineReadSetSection::core('department_memberships', $this->rows(
                DepartmentMembership::query()
                    ->active()
                    ->whereIn('staff_id', $scope->staffIds)
                    ->orderBy('id'),
                fn (DepartmentMembership $membership): array => [
                    'id' => (string) $membership->getKey(),
                    'department_id' => (string) $membership->department_id,
                    'staff_id' => (string) $membership->staff_id,
                    'status' => $membership->status,
                    'archived_at' => $this->moment($membership->archived_at),
                ],
            )),

            OfflineReadSetSection::core('team_memberships', $this->rows(
                TeamMembership::query()
                    ->active()
                    ->whereIn('staff_id', $scope->staffIds)
                    ->orderBy('id'),
                fn (TeamMembership $membership): array => [
                    'id' => (string) $membership->getKey(),
                    'team_id' => (string) $membership->team_id,
                    'staff_id' => (string) $membership->staff_id,
                    'department_membership_id' => $membership->department_membership_id === null
                        ? null
                        : (string) $membership->department_membership_id,
                    'membership_role' => $membership->membership_role,
                    'archived_at' => $this->moment($membership->archived_at),
                ],
            )),

            OfflineReadSetSection::core('events', $this->rows(
                Event::query()->whereKey($scope->eventIds)->orderBy('starts_at')->orderBy('name'),
                fn (Event $event): array => [
                    'id' => (string) $event->getKey(),
                    'organization_id' => (string) $event->organization_id,
                    'name' => $event->name,
                    'slug' => $event->slug,
                    'status' => $event->status,
                    'timezone' => $event->timezone,
                    'starts_at' => $this->moment($event->starts_at),
                    'ends_at' => $this->moment($event->ends_at),
                    // The window an offline client bounds its own staleness by
                    // (technical spec 11A.4).
                    'active_event_window_starts_at' => $this->moment($event->active_event_window_starts_at),
                    'active_event_window_ends_at' => $this->moment($event->active_event_window_ends_at),
                    'archived_at' => $this->moment($event->archived_at),
                ],
            )),

            OfflineReadSetSection::core('event_department_assignments', $this->rows(
                EventDepartmentAssignment::query()
                    ->whereIn('event_id', $scope->eventIds)
                    ->whereIn('department_id', $scope->departmentIds)
                    ->whereNull('archived_at')
                    ->orderBy('id'),
                fn (EventDepartmentAssignment $assignment): array => [
                    'id' => (string) $assignment->getKey(),
                    'event_id' => (string) $assignment->event_id,
                    'department_id' => (string) $assignment->department_id,
                ],
            )),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Their own shifts (Scheduling)
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<OfflineReadSetSection>
     */
    private function schedulingSections(OfflineReadSetScope $scope): array
    {
        $assignments = ShiftAssignment::query()
            ->whereIn('staff_id', $scope->staffIds)
            ->whereNull('removed_at')
            ->orderBy('id')
            ->get();

        $shiftIds = $assignments
            ->map(fn (ShiftAssignment $assignment): string => (string) $assignment->shift_id)
            ->unique()
            ->values()
            ->all();

        return [
            OfflineReadSetSection::owned('shift_assignments', ModuleKey::Scheduling, $this->map(
                $assignments,
                fn (ShiftAssignment $assignment): array => [
                    'id' => (string) $assignment->getKey(),
                    'shift_id' => (string) $assignment->shift_id,
                    'staff_id' => (string) $assignment->staff_id,
                    'assignment_status' => $assignment->assignment_status,
                ],
            )),

            OfflineReadSetSection::owned('shifts', ModuleKey::Scheduling, $this->rows(
                Shift::query()->whereKey($shiftIds)->orderBy('starts_at')->orderBy('id'),
                fn (Shift $shift): array => [
                    'id' => (string) $shift->getKey(),
                    'event_id' => (string) $shift->event_id,
                    'department_id' => (string) $shift->department_id,
                    'eligible_team_id' => $shift->eligible_team_id === null
                        ? null
                        : (string) $shift->eligible_team_id,
                    'title' => $shift->title,
                    // The snapshots the shift already carries, so a device can
                    // name the department and team a shift belongs to without
                    // holding either record.
                    'department_name_snapshot' => $shift->department_name_snapshot,
                    'team_name_snapshot' => $shift->team_name_snapshot,
                    'starts_at' => $this->moment($shift->starts_at),
                    'ends_at' => $this->moment($shift->ends_at),
                    'capacity' => $shift->capacity,
                    'signup_opens_at' => $this->moment($shift->signup_opens_at),
                    'signup_closes_at' => $this->moment($shift->signup_closes_at),
                    'schedule_lock_at' => $this->moment($shift->schedule_lock_at),
                    'cancelled_at' => $this->moment($shift->cancelled_at),
                ],
            )),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Documents, fragments, and acknowledgment state (Documents)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{policy: list<string>, procedure: list<string>}  $documents
     * @return list<OfflineReadSetSection>
     */
    private function documentSections(OfflineReadSetScope $scope, array $documents): array
    {
        $references = DocumentFragmentReference::query()
            ->where(function (Builder $query) use ($documents): void {
                $query
                    ->where(fn (Builder $policy) => $policy
                        ->where('document_type', DocumentFragmentReference::DOCUMENT_TYPE_POLICY)
                        ->whereIn('document_id', $documents['policy']))
                    ->orWhere(fn (Builder $procedure) => $procedure
                        ->where('document_type', DocumentFragmentReference::DOCUMENT_TYPE_PROCEDURE)
                        ->whereIn('document_id', $documents['procedure']));
            })
            ->orderBy('id')
            ->get();

        $fragmentIds = $references
            ->map(fn (DocumentFragmentReference $reference): string => (string) $reference->fragment_id)
            ->unique()
            ->values()
            ->all();

        return [
            OfflineReadSetSection::owned('policy_documents', ModuleKey::Documents, $this->rows(
                PolicyDocument::query()->whereKey($documents['policy'])->orderBy('title'),
                fn (PolicyDocument $document): array => $this->documentRow($document),
            )),

            OfflineReadSetSection::owned('procedure_documents', ModuleKey::Documents, $this->rows(
                ProcedureDocument::query()->whereKey($documents['procedure'])->orderBy('title'),
                fn (ProcedureDocument $document): array => $this->documentRow($document),
            )),

            /*
             * Fragments reach the device through the documents that reference
             * them and through nothing else. A fragment is organization,
             * department, or team scoped in its own right, but its audience
             * offline is the audience of the published documents it appears in
             * — which is what "published fragments referenced by those visible
             * documents" says (technical spec 9.3), and what keeps a fragment
             * from being a second, wider way into governance text.
             */
            OfflineReadSetSection::owned('document_fragment_references', ModuleKey::Documents, $this->map(
                $references,
                fn (DocumentFragmentReference $reference): array => [
                    'id' => (string) $reference->getKey(),
                    'document_type' => $reference->document_type,
                    'document_id' => (string) $reference->document_id,
                    'fragment_id' => (string) $reference->fragment_id,
                    'token' => $reference->token,
                    'fragment_version_at_last_edit' => $reference->fragment_version_at_last_edit,
                ],
            )),

            OfflineReadSetSection::owned('document_fragments', ModuleKey::Documents, $this->rows(
                DocumentFragment::query()->whereKey($fragmentIds)->orderBy('name'),
                fn (DocumentFragment $fragment): array => [
                    'id' => (string) $fragment->getKey(),
                    'organization_id' => (string) $fragment->organization_id,
                    'scope_type' => $fragment->scope_type,
                    'scope_id' => (string) $fragment->scope_id,
                    'name' => $fragment->name,
                    'slug' => $fragment->slug,
                    'markdown_source' => $fragment->markdown_source,
                    'version' => $fragment->version,
                ],
            )),

            /*
             * What the caller is required to acknowledge, and what they have
             * acknowledged. Both halves travel because one without the other
             * cannot answer the question a device with no signal is asked:
             * am I outstanding on anything.
             *
             * Acknowledging is still connected-only (technical spec 9.4), so
             * these are read state and the device queues nothing against them.
             */
            OfflineReadSetSection::owned('document_acknowledgment_requirements', ModuleKey::Documents, $this->rows(
                DocumentAcknowledgmentRequirement::query()
                    ->where('active', true)
                    ->where(function (Builder $query) use ($documents): void {
                        $query
                            ->where(fn (Builder $policy) => $policy
                                ->where('document_type', DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY)
                                ->whereIn('document_id', $documents['policy']))
                            ->orWhere(fn (Builder $procedure) => $procedure
                                ->where('document_type', DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_PROCEDURE)
                                ->whereIn('document_id', $documents['procedure']));
                    })
                    ->orderBy('id'),
                fn (DocumentAcknowledgmentRequirement $requirement): array => [
                    'id' => (string) $requirement->getKey(),
                    'organization_id' => (string) $requirement->organization_id,
                    'scope_type' => $requirement->scope_type,
                    'scope_id' => (string) $requirement->scope_id,
                    'document_type' => $requirement->document_type,
                    'document_id' => (string) $requirement->document_id,
                    'requirement_context' => $requirement->requirement_context,
                ],
            )),

            OfflineReadSetSection::owned('document_acknowledgments', ModuleKey::Documents, $this->rows(
                DocumentAcknowledgment::query()
                    ->where('user_id', (string) $scope->user->getKey())
                    ->orderBy('acknowledged_at')
                    ->orderBy('id'),
                fn (DocumentAcknowledgment $acknowledgment): array => [
                    'id' => (string) $acknowledgment->getKey(),
                    'staff_id' => $acknowledgment->staff_id === null
                        ? null
                        : (string) $acknowledgment->staff_id,
                    'document_type' => $acknowledgment->document_type,
                    'document_id' => (string) $acknowledgment->document_id,
                    // The revisions POL-045 measures satisfaction against: an
                    // acknowledgment made at an earlier version stays an
                    // acknowledgment, and a device deciding that offline needs
                    // the version it was made at.
                    'document_revision' => $acknowledgment->document_revision,
                    'fragment_revision' => $acknowledgment->fragment_revision,
                    'scope_type' => $acknowledgment->scope_type,
                    'scope_id' => (string) $acknowledgment->scope_id,
                    'acknowledged_at' => $this->moment($acknowledgment->acknowledged_at),
                ],
            )),
        ];
    }

    /**
     * The published documents in the caller's audience, by type.
     *
     * The audience rule is the one
     * {@see \App\Services\Documents\DocumentProductAccess::canViewPublishedDocument}
     * applies to a single document, asked once for the whole set instead of
     * once per row: an organization-scoped document reaches the staff of that
     * organization, a department-scoped one its members, a team-scoped one
     * theirs. Unpublished documents reach nobody through this list at any
     * scope, which is the property M8.2 asserted and this preserves.
     *
     * @return array{policy: list<string>, procedure: list<string>}
     */
    private function visibleDocuments(OfflineReadSetScope $scope): array
    {
        return [
            'policy' => $this->publishedDocumentIds(PolicyDocument::class, $scope),
            'procedure' => $this->publishedDocumentIds(ProcedureDocument::class, $scope),
        ];
    }

    /**
     * @param  class-string<PolicyDocument|ProcedureDocument>  $documentClass
     * @return list<string>
     */
    private function publishedDocumentIds(string $documentClass, OfflineReadSetScope $scope): array
    {
        /** @var list<string> $ids */
        $ids = $documentClass::query()
            ->where('state', $documentClass::STATE_PUBLISHED)
            ->where(function (Builder $scoped) use ($documentClass, $scope): void {
                $scoped
                    ->where(fn (Builder $organization) => $organization
                        ->where('scope_type', $documentClass::SCOPE_ORGANIZATION)
                        ->whereIn('scope_id', $scope->standingOrganizationIds))
                    ->orWhere(fn (Builder $department) => $department
                        ->where('scope_type', $documentClass::SCOPE_DEPARTMENT)
                        ->whereIn('scope_id', $scope->departmentIds))
                    ->orWhere(fn (Builder $team) => $team
                        ->where('scope_type', $documentClass::SCOPE_TEAM)
                        ->whereIn('scope_id', $scope->teamIds));
            })
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRow(PolicyDocument|ProcedureDocument $document): array
    {
        return [
            'id' => (string) $document->getKey(),
            'organization_id' => (string) $document->organization_id,
            'scope_type' => $document->scope_type,
            'scope_id' => (string) $document->scope_id,
            'title' => $document->title,
            'slug' => $document->slug,
            'markdown_source' => $document->markdown_source,
            'document_revision' => $document->document_revision,
            'fragment_revision' => $document->fragment_revision,
            'published_at' => $this->moment($document->published_at),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The Field Report form and their own reports (Incident Management)
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<OfflineReadSetSection>
     */
    private function fieldReportSections(OfflineReadSetScope $scope): array
    {
        /*
         * Narrowed to the events in scope as well as to authorship. The
         * author's own reports are theirs to read wherever they filed them, and
         * they read them online through the surfaces that serve them; what
         * travels offline is bounded by the events this set is about, which is
         * also what lets the module boundary apply to a section whose rows
         * carry no organization of their own.
         */
        $reports = FieldReport::query()
            ->forAuthor($scope->user)
            ->whereIn('event_id', $scope->eventIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $reportIds = $reports
            ->map(fn (FieldReport $report): string => (string) $report->getKey())
            ->all();

        return [
            /*
             * "Field report form" (technical spec 9.3). The form is a section
             * of one row rather than a shape of its own, so a client stores and
             * versions it the same way it stores everything else here.
             *
             * It carries the limits the server enforces rather than a copy the
             * client maintains, because a device composing a report with no
             * signal is deciding right then whether a third photo can be
             * attached, and being told no at replay is the outcome the offline
             * write path exists to avoid.
             */
            OfflineReadSetSection::owned('field_report_form', ModuleKey::IncidentManagement, [[
                'command' => 'submit-field-report',
                'offline_writable' => true,
                'fields' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'body', 'type' => 'text', 'required' => true],
                    ['name' => 'event_id', 'type' => 'uuid', 'required' => true],
                    ['name' => 'staff_id', 'type' => 'uuid', 'required' => true],
                    ['name' => 'department_id', 'type' => 'uuid', 'required' => false],
                    ['name' => 'team_id', 'type' => 'uuid', 'required' => false],
                    ['name' => 'temporary_local_number', 'type' => 'string', 'required' => false, 'max_length' => 64],
                    ['name' => 'device_submitted_at', 'type' => 'datetime', 'required' => true],
                    ['name' => 'origin_device_id', 'type' => 'uuid', 'required' => true],
                ],
                'photos' => [
                    'max_count' => FieldReportPhotoLimits::MAX_COUNT,
                    'max_bytes' => FieldReportPhotoLimits::MAX_BYTES,
                    'max_width' => FieldReportPhotoLimits::MAX_WIDTH,
                    'max_height' => FieldReportPhotoLimits::MAX_HEIGHT,
                    'preferred_mime' => FieldReportPhotoLimits::PREFERRED_MIME,
                    'fallback_mime' => FieldReportPhotoLimits::FALLBACK_MIME,
                    'rejected_mime' => FieldReportPhotoLimits::REJECTED_MIME,
                ],
            ]]),

            /*
             * "Their own submitted field reports" — the two columns FR-015
             * separates, because a taken report has an author and a submitter
             * and both reach it. This is the model's own `forAuthor` scope, so
             * the set and the product agree by construction about whose report
             * this is.
             */
            OfflineReadSetSection::owned('field_reports', ModuleKey::IncidentManagement, $this->map(
                $reports,
                fn (FieldReport $report): array => [
                    'id' => (string) $report->getKey(),
                    'event_id' => (string) $report->event_id,
                    'department_id' => $report->department_id === null ? null : (string) $report->department_id,
                    'team_id' => $report->team_id === null ? null : (string) $report->team_id,
                    'staff_id' => $report->staff_id === null ? null : (string) $report->staff_id,
                    'submitted_by_user_id' => $report->submitted_by_user_id === null
                        ? null
                        : (string) $report->submitted_by_user_id,
                    'fra_number' => $report->fra_number,
                    'temporary_local_number' => $report->temporary_local_number,
                    'title' => $report->title,
                    'body' => $report->body,
                    'sync_status' => $report->sync_status,
                    'device_submitted_at' => $this->moment($report->device_submitted_at),
                    'server_received_at' => $this->moment($report->server_received_at),
                    'created_at' => $this->moment($report->created_at),
                ],
            )),

            /*
             * Appends travel with the reports they belong to. A report is
             * immutable and its appends are how it continues (FR-009), so a
             * device holding the report without them would show a version of
             * events its author knows to be incomplete.
             */
            OfflineReadSetSection::owned('field_report_appends', ModuleKey::IncidentManagement, $this->rows(
                FieldReportAppend::query()
                    ->whereIn('field_report_id', $reportIds)
                    ->orderBy('device_submitted_at')
                    ->orderBy('id'),
                fn (FieldReportAppend $append): array => [
                    'id' => (string) $append->getKey(),
                    'field_report_id' => (string) $append->field_report_id,
                    'body' => $append->body,
                    'device_submitted_at' => $this->moment($append->device_submitted_at),
                    'server_received_at' => $this->moment($append->server_received_at),
                ],
            )),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shaping
    |--------------------------------------------------------------------------
    */

    /**
     * @template TModel of Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function rows($query, callable $row): array
    {
        return $this->map($query->get(), $row);
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @param  callable(TModel): array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function map(Collection $models, callable $row): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $models->map($row)->values()->all();

        return $rows;
    }

    /**
     * A timestamp as the device reads it, or null.
     *
     * Every moment in the set is ISO 8601 with its offset. A device compares
     * these against its own clock while it has no way to ask the node what time
     * it is, and a bare local-looking string would be the wrong moment on a
     * device whose timezone is not the event's.
     */
    private function moment(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return (string) $value;
    }
}
