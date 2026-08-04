<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DocumentAcknowledgment;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\FieldReport;
use App\Models\HoursWorked;
use App\Models\Incident;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\Shift\ShiftAdminAccess;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Support\DevelopmentScenarioCatalog;
use Database\Seeders\Support\ScenarioClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The operational development scenario.
 *
 * The old seed was topology — an organization, some departments, some people —
 * and it was verifiable by counting rows. This one is a situation, and what has
 * to hold about it is not a count but a shape: the schedule has to straddle the
 * moment it was seeded, and every state a surface can render has to have
 * somebody standing in it.
 *
 * That is worth a test rather than a manual look, because the failure mode is
 * quiet. A seed that drifts — a shift that ends up entirely in the past, a crew
 * where everybody happens to be checked in — still seeds without error and
 * still looks plausible in the database. It just leaves whoever opens the
 * Logistics Desk with nothing to press, and they find out by wasting an
 * afternoon on it.
 */
class OperationalScenarioSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ScenarioClock::reset();
    }

    protected function tearDown(): void
    {
        ScenarioClock::reset();

        parent::tearDown();
    }

    public function test_the_schedule_straddles_the_moment_it_was_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);

        $now = ScenarioClock::now();
        $shifts = Shift::query()->where('event_id', $this->runningEvent()->id)->get();

        $running = $shifts->filter(
            fn (Shift $shift): bool => $shift->starts_at->lessThanOrEqualTo($now)
                && $shift->ends_at->greaterThan($now)
                && ! $shift->isCancelled(),
        );

        $finished = $shifts->filter(fn (Shift $shift): bool => $shift->ends_at->lessThanOrEqualTo($now));
        $upcoming = $shifts->filter(fn (Shift $shift): bool => $shift->starts_at->greaterThan($now));

        $this->assertGreaterThanOrEqual(2, $running->count(), 'Something has to be running to check people into.');
        $this->assertGreaterThanOrEqual(2, $finished->count(), 'Something has to be over to correct hours on.');
        $this->assertGreaterThanOrEqual(2, $upcoming->count(), 'Something has to be ahead to sign up for.');

        /*
         * The Logistics Desk indexes twelve hours back and thirty-six forward.
         * A scenario whose shifts all fall outside that window seeds cleanly and
         * leaves the desk empty, which is the exact failure this scenario exists
         * to prevent.
         */
        $inDeskHorizon = $shifts->filter(
            fn (Shift $shift): bool => $shift->ends_at->greaterThanOrEqualTo($now->copy()->subHours(12))
                && $shift->starts_at->lessThanOrEqualTo($now->copy()->addHours(36)),
        );

        $this->assertGreaterThanOrEqual(6, $inDeskHorizon->count());
        $this->assertTrue($shifts->contains(fn (Shift $shift): bool => $shift->isCancelled()));
    }

    public function test_every_attendance_state_has_somebody_standing_in_it(): void
    {
        $this->seed(DatabaseSeeder::class);

        $states = AttendanceRecord::query()
            ->whereIn('shift_id', Shift::query()->where('event_id', $this->runningEvent()->id)->select('id'))
            ->pluck('current_state')
            ->unique()
            ->values()
            ->all();

        foreach (
            [
                AttendanceRecord::STATE_CHECKED_IN,
                AttendanceRecord::STATE_CHECKED_OUT,
                AttendanceRecord::STATE_NO_SHOW,
            ] as $state
        ) {
            $this->assertContains($state, $states, "No seeded staff member is in the {$state} state.");
        }

        // Somebody assigned to a running shift who has not arrived, which is the
        // only state that offers both check-in and mark-no-show.
        $awaiting = AttendanceRecord::query()
            ->where('current_state', AttendanceRecord::STATE_SCHEDULED)
            ->exists();

        $unrecorded = Shift::query()
            ->where('event_id', $this->runningEvent()->id)
            ->whereHas('assignments', fn ($query) => $query->whereNull('removed_at'))
            ->get()
            ->contains(function (Shift $shift): bool {
                $assigned = $shift->assignments()->whereNull('removed_at')->count();
                $recorded = AttendanceRecord::query()->where('shift_id', $shift->id)->count();

                return $assigned > $recorded;
            });

        $this->assertTrue(
            $awaiting || $unrecorded,
            'Nobody is on a roster without an attendance record, so check-in cannot be exercised.',
        );
    }

    /**
     * Both sides of the correction window (HOURS-007, HOURS-008).
     *
     * One open record and one frozen record, or the Logistics Desk can only ever
     * show half of what hours correction does.
     */
    public function test_hours_exist_on_both_sides_of_the_correction_window(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(
            HoursWorked::query()->whereNull('frozen_at')->exists(),
            'No open hours record, so nothing can be corrected.',
        );
        $this->assertTrue(
            HoursWorked::query()->whereNotNull('frozen_at')->exists(),
            'No frozen hours record, so the closed grace period cannot be shown refusing one.',
        );

        // A correction that already happened, so the audit trail has a before
        // and an after in it before anybody touches a screen.
        $this->assertTrue(
            HoursWorked::query()->whereNotNull('corrected_by_user_id')->exists(),
        );
    }

    /**
     * The three equipment states the off-site block turns on (SLB-017, SLB-018).
     */
    public function test_equipment_covers_the_states_the_off_site_block_turns_on(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(
            EquipmentCheckout::query()->whereNull('returned_at')->whereNotNull('shift_id')->exists(),
            'No shift-scoped checkout, so kit that comes back at end of shift is unrepresented.',
        );
        $this->assertTrue(
            EquipmentCheckout::query()->whereNull('returned_at')->whereNull('shift_id')->exists(),
            'No event-scoped checkout, so EQUIP-009 has no data behind it.',
        );

        // Outstanding but written off: still owed, and no longer a reason to
        // hold its holder on site. The subtlest rule at the desk.
        $writtenOffButOut = EquipmentCheckout::query()
            ->whereNull('returned_at')
            ->whereHas('equipmentItem', fn ($query) => $query->whereIn('status', [
                EquipmentItem::STATUS_MISSING,
                EquipmentItem::STATUS_DAMAGED,
            ]))
            ->exists();

        $this->assertTrue($writtenOffButOut);

        foreach ([EquipmentItem::STATUS_AVAILABLE, EquipmentItem::STATUS_CHECKED_OUT] as $status) {
            $this->assertTrue(EquipmentItem::query()->where('status', $status)->exists());
        }

        $this->assertTrue(EquipmentItem::query()->whereNotNull('archived_at')->exists());
    }

    public function test_the_document_library_holds_every_state_and_a_shared_fragment(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (
            [
                PolicyDocument::STATE_DRAFT,
                PolicyDocument::STATE_PUBLISHED,
                PolicyDocument::STATE_ARCHIVED,
            ] as $state
        ) {
            $this->assertTrue(
                PolicyDocument::query()->where('state', $state)->exists(),
                "No policy document is in the {$state} state, so that filter has nothing to show.",
            );
        }

        $this->assertTrue(
            PolicyDocument::query()->whereNotNull('event_info_section')->exists(),
            'No document is placed in an Event Info section, so that page is entirely empty states.',
        );

        // Acknowledged by some and not others, so both columns of the
        // organizer's view are populated.
        $this->assertGreaterThan(0, DocumentAcknowledgment::query()->count());
    }

    public function test_the_incident_list_has_enough_spread_to_filter(): void
    {
        $this->seed(DatabaseSeeder::class);

        $incidents = Incident::query()->where('event_id', $this->runningEvent()->id)->get();

        $this->assertGreaterThanOrEqual(5, $incidents->count());
        $this->assertGreaterThanOrEqual(3, $incidents->pluck('status')->unique()->count());
        $this->assertGreaterThanOrEqual(3, $incidents->pluck('priority_label')->unique()->count());

        // One Field Report attached to an incident and one still loose, so the
        // review list has something outstanding and the picker has a subject.
        $this->assertGreaterThanOrEqual(3, FieldReport::query()->count());
        $this->assertTrue(
            Incident::query()->whereHas('fieldReportLinks', fn ($query) => $query->whereNull('unlinked_at'))->exists(),
        );
    }

    public function test_the_application_queue_holds_decided_and_undecided_rows(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (
            [
                EventApplication::STATUS_SUBMITTED,
                EventApplication::STATUS_APPROVED,
                EventApplication::STATUS_REJECTED,
                EventApplication::STATUS_DEFERRED,
            ] as $status
        ) {
            $this->assertTrue(
                EventApplication::query()->where('status', $status)->exists(),
                "The review queue has no {$status} application.",
            );
        }
    }

    /**
     * Pictures on all three of the paths that carry them.
     *
     * An empty image slot and a working one look identical in a database and
     * completely different on screen, so each of the three is asserted where it
     * actually lands: a path on the staff row, a branding attachment on the
     * owner, and a Field Report attachment on the private disk.
     */
    public function test_the_scenario_carries_images_on_every_path_that_holds_them(): void
    {
        $this->seed(DatabaseSeeder::class);

        $withPictures = Staff::query()->whereNotNull('profile_picture_path')->get();

        $this->assertGreaterThanOrEqual(10, $withPictures->count());
        $this->assertTrue(
            Staff::query()->whereNull('profile_picture_path')->exists(),
            'Everybody has an avatar, so the lettermark fallback is never rendered.',
        );

        foreach ($withPictures as $member) {
            $this->assertTrue(
                Storage::disk('public')->exists((string) $member->profile_picture_path),
                "{$member->legal_name} has a picture path with no file behind it.",
            );
            $this->assertNotNull($member->profile_picture_width);
            $this->assertSame('image/png', $member->profile_picture_mime_type);
        }

        // Branding, on every owner type the service accepts.
        $this->assertNotNull($this->runningEvent()->branding_logo_attachment_id);
        $this->assertTrue(
            Department::query()->whereNotNull('branding_logo_attachment_id')->count() >= 3,
        );
        $this->assertTrue(Team::query()->whereNotNull('branding_logo_attachment_id')->exists());

        $organization = $this->runningEvent()->organization;
        $this->assertNotNull($organization->branding_full_lockup_attachment_id);
        $this->assertNotNull($organization->branding_compact_mark_attachment_id);

        // Field Report photos, stored and re-encoded by the upload path rather
        // than written straight to the table.
        $photos = Attachment::query()
            ->where('attachable_type', (new FieldReport)->getMorphClass())
            ->get();

        $this->assertGreaterThanOrEqual(2, $photos->count());

        foreach ($photos as $photo) {
            $this->assertNotSame('', (string) $photo->checksum);
            $this->assertContains($photo->mime_type, ['image/webp', 'image/jpeg']);
            $this->assertTrue(Storage::disk(config('filesystems.attachments_disk'))->exists((string) $photo->storage_path));
        }

        // One report deliberately has none, so the empty layout is reachable.
        $this->assertTrue(
            FieldReport::query()
                ->whereDoesntHave('attachments')
                ->exists(),
        );
    }

    /**
     * Re-seeding re-anchors rather than stacking a second schedule.
     *
     * The operational seeders are idempotent on natural keys, so running the
     * seed twice against one database has to leave the same scenario rather than
     * two overlapping copies of it.
     */
    public function test_the_team_lead_leads_exactly_the_dirt_crew_and_credits_resolve_both_ways(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Tess is the narrow team-lead path (M18.16): Dirt's shifts and their
        // credit policy, and nothing wider. Sam cannot prove this boundary —
        // his department_administration opens every team before shift_lead
        // gets a say.
        $tess = User::query()
            ->where('email', 'tess.teamlead@northwood-collective.test')
            ->firstOrFail();
        $rangers = Department::query()->where('code', 'RANGERS')->firstOrFail();
        $dirt = Team::query()
            ->where('department_id', $rangers->id)
            ->where('code', 'DIRT')
            ->firstOrFail();

        $access = app(ShiftAdminAccess::class);

        $this->assertSame([(string) $dirt->id], $access->manageableTeamIds($tess, $rangers));
        $this->assertFalse($access->canAdministerDepartment($tess, $rangers));

        // Her lead designation elevates nobody else on the crew: Vera stays
        // exactly as ordinary as the catalog says she is.
        $vera = User::query()
            ->where('email', 'vera.staff@northwood-collective.test')
            ->firstOrFail();
        $this->assertSame([], $access->manageableTeamIds($vera, $rangers));

        // Both credit resolutions exist in the scenario: the shift override on
        // Overnight Patrol (SHIFT-010) and the organization default behind
        // every other shift (CREDIT-003).
        $organization = $rangers->organization;
        $this->assertNotNull($organization->default_credit_policy_id, 'Standard Hour should be the organization default.');

        $overnight = Shift::query()
            ->where('event_id', $this->runningEvent()->id)
            ->where('title', 'Overnight Patrol')
            ->firstOrFail();
        $this->assertNotNull($overnight->credit_policy_id);
        $this->assertNotSame(
            (string) $organization->default_credit_policy_id,
            (string) $overnight->credit_policy_id,
            'The override has to differ from the default for either to be provable.',
        );
    }

    public function test_reseeding_does_not_duplicate_the_scenario(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstRun = $this->scenarioCounts();

        ScenarioClock::reset();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($firstRun, $this->scenarioCounts());
    }

    /**
     * The clock is one instant for the whole run.
     *
     * Every anchor is derived from it, so a scenario seeded over eleven seconds
     * cannot place a shift and the check-in against it a few seconds apart.
     */
    public function test_the_scenario_clock_holds_one_instant_for_the_whole_run(): void
    {
        $first = ScenarioClock::now();
        $second = ScenarioClock::now();

        $this->assertTrue($first->equalTo($second));
        $this->assertSame(0, $first->second, 'Seconds carry no meaning here and only make output harder to compare.');

        ScenarioClock::reset();

        $this->assertNotSame($first, ScenarioClock::now());
    }

    public function test_no_seeded_record_mentions_the_retired_scenario_identity(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            DevelopmentScenarioCatalog::ORGANIZATION_NAME,
            $this->runningEvent()->organization->name,
        );

        $this->assertSame(0, Staff::query()->where('email', 'like', '%idaho%')->count());
        $this->assertSame(0, Event::query()->where('slug', 'like', '%idaho%')->count());
    }

    /**
     * @return array<string, int>
     */
    private function scenarioCounts(): array
    {
        return [
            'shifts' => Shift::query()->count(),
            'assignments' => ShiftAssignment::query()->count(),
            'attendance' => AttendanceRecord::query()->count(),
            'hours' => HoursWorked::query()->count(),
            'equipment' => EquipmentItem::query()->count(),
            'checkouts' => EquipmentCheckout::query()->count(),
            'policies' => PolicyDocument::query()->count(),
            'incidents' => Incident::query()->count(),
            'field_reports' => FieldReport::query()->count(),
            'applications' => EventApplication::query()->count(),
        ];
    }

    private function runningEvent(): Event
    {
        return Event::query()
            ->where('slug', DevelopmentScenarioCatalog::EVENT_SLUG)
            ->firstOrFail();
    }
}
