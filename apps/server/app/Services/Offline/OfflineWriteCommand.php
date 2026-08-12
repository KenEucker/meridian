<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Domain\Modules\SyncedTable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The Alpha 1 offline writes, on the node's side (MOD-017; technical spec 9.4,
 * 11A.5; data/API 5.3, 5.6, 7.2, 5.8A; M19.17).
 *
 * The client has carried this list since M16.10 (`commandCatalog.ts`) because it
 * has to decide whether a command may be queued at all. The node needed no copy
 * while every queued write was answered the same way a live one was. MOD-017 is
 * where that stops: a queued write refused because its module went inactive is
 * recorded as a sync conflict, and a live request refused for the same reason is
 * not, so the node has to be able to tell one from the other.
 *
 * It tells them apart by the device-generated operation UUID. Data/API 7.2 is
 * explicit that "an offline-writable command carries the device-generated
 * operation UUID as its idempotency key", and it is the only thing in a request
 * that says the write was composed on a device rather than typed at a desk with a
 * node in front of it. A command listed here that arrives without one is a live
 * request and is refused and forgotten, the way every other refusal is.
 *
 * `mark-staff-on-site` therefore declares no key, and that is deliberate rather
 * than an omission: data/API 7.2 says it "needs no key of its own", because
 * marking somebody on-site who already is changes nothing and says so. It is core
 * capability (MOD-004) and can never reach an inactive module, so nothing is lost
 * by it having no conflict path.
 *
 * The list is closed by data/API 5.6 and 7.2, and widened only by amendment to
 * the specification — 5.8A added the Event Horizon's two preference commands.
 * `OfflineWriteConflictCoverageTest` reads 7.2 and 5.8A out of the document and
 * asserts this enum still says what they say, and asserts that every one of them
 * whose route carries a module gate can describe the write it refused.
 */
enum OfflineWriteCommand: string
{
    case SubmitFieldReport = 'submit-field-report';

    case UploadFieldReportPhoto = 'upload-field-report-photo';

    case CheckInStaff = 'check-in-staff';

    case CheckOutStaff = 'check-out-staff';

    case MarkNoShow = 'mark-no-show';

    case MarkStaffOnSite = 'mark-staff-on-site';

    case AddStaffToShift = 'add-staff-to-shift';

    case OverrideShiftAddition = 'override-shift-addition';

    case HideEventHorizon = 'hide-event-horizon';

    case ShowEventHorizon = 'show-event-horizon';

    /**
     * The named route this command is submitted to (data/API 5.2).
     */
    public function routeName(): string
    {
        return 'api.commands.'.$this->value;
    }

    /**
     * The request field carrying the device-generated idempotency key, or null
     * where the command has none.
     */
    public function operationKey(): ?string
    {
        return match ($this) {
            // The Field Report and its photos are keyed by the record's own
            // client-minted id, which is what the device queues them under.
            self::SubmitFieldReport,
            self::UploadFieldReportPhoto => 'id',

            self::CheckInStaff,
            self::CheckOutStaff,
            self::MarkNoShow,
            self::AddStaffToShift,
            self::OverrideShiftAddition => 'operation_uuid',

            // Data/API 7.2: the on-site mark needs no key of its own.
            self::MarkStaffOnSite => null,

            // The Event Horizon's preferences are self-scoped view state and
            // name nothing but the event (data/API 5.8A).
            self::HideEventHorizon,
            self::ShowEventHorizon => null,
        };
    }

    /**
     * The request field naming the record this write is about.
     *
     * A conflict row has to say what the refused work was about, and for these
     * commands that is the subject the request names rather than a record the
     * node created — because it never got as far as creating one.
     */
    public function subjectKey(): ?string
    {
        return match ($this) {
            self::SubmitFieldReport => 'id',
            self::UploadFieldReportPhoto => 'field_report_id',
            self::AddStaffToShift,
            self::OverrideShiftAddition => 'shift_id',
            default => null,
        };
    }

    /**
     * The synced table this write is about (data/API 7.6), which is where its
     * owning module comes from.
     *
     * Declared rather than inferred, and cross-checked against the module the
     * route gate is declared with: a command whose route gates on Scheduling and
     * whose subject table belongs to Incident Management is one of the two
     * declarations being wrong, and the coverage test says so rather than
     * recording a conflict that names the wrong module.
     */
    public function syncedTable(): ?SyncedTable
    {
        return match ($this) {
            self::SubmitFieldReport,
            self::UploadFieldReportPhoto => SyncedTable::FieldReports,
            self::AddStaffToShift,
            self::OverrideShiftAddition => SyncedTable::ShiftAssignments,
            self::CheckInStaff,
            self::CheckOutStaff,
            self::MarkNoShow => SyncedTable::AttendanceRecords,
            self::MarkStaffOnSite => SyncedTable::EventDepartmentPresences,
            // The dismissal is personal view state on a table no device holds
            // (HORIZON-012), so there is no synced table to name.
            self::HideEventHorizon,
            self::ShowEventHorizon => null,
        };
    }

    /**
     * The module that owns this write, or null when it is core (MOD-004).
     */
    public function module(): ?ModuleKey
    {
        return $this->syncedTable()?->module();
    }

    /**
     * How a conflict row names the subject of this write.
     *
     * Singular, matching the `entity_type` a node operation carries
     * (`attendance_record`), so one queue reads one way whichever side of the
     * product filed the row.
     */
    public function entityType(): ?string
    {
        $table = $this->syncedTable();

        return $table === null ? null : Str::singular($table->value);
    }

    /**
     * The command a named route submits, or null for a route that is not an
     * offline write.
     */
    public static function forRoute(?string $routeName): ?self
    {
        if ($routeName === null || ! str_starts_with($routeName, 'api.commands.')) {
            return null;
        }

        return self::tryFrom(substr($routeName, strlen('api.commands.')));
    }

    /**
     * The device-generated key this request was queued under, or null when the
     * request carries none — which is what a live request looks like.
     */
    public function keyOf(Request $request): ?string
    {
        $key = $this->operationKey();

        if ($key === null) {
            return null;
        }

        $value = $request->input($key);

        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    /**
     * The id of the record this request is about, or null when the request does
     * not name one readably.
     */
    public function subjectOf(Request $request): ?string
    {
        $key = $this->subjectKey();

        if ($key === null) {
            return null;
        }

        $value = $request->input($key);

        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
