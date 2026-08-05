<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Equipment\EquipmentCheckoutException;
use App\Services\Equipment\EquipmentCheckoutPresentation;
use App\Services\Equipment\EquipmentCheckoutService;
use App\Services\Equipment\EquipmentInventoryException;
use App\Services\Equipment\EquipmentInventoryService;
use App\Services\Equipment\EquipmentLookupService;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Equipment assignment scope, pooled tracking, and lookup (M18.24, M18.24B,
 * M18.24C; EQUIP-005, EQUIP-009 through EQUIP-013, EQUIP-015 through EQUIP-017;
 * data/API 10.13; UI contract 9.6, 9.6A).
 */
class EquipmentScopeAndPoolingTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // M18.24 — assignment scope and derived presentation states
    // ---------------------------------------------------------------

    /**
     * EQUIP-005's whole point, stated as a schema assertion.
     *
     * Overdue and unknown are readings, not records. If somebody ever adds a
     * column for one, this fails before the second source of truth reaches a
     * screen and starts disagreeing with the first.
     */
    public function test_no_stored_state_was_added_for_the_derived_readings(): void
    {
        $this->assertSame([
            'available',
            'checked_out',
            'returned',
            'missing',
            'damaged',
        ], EquipmentItem::statuses());

        foreach (['overdue', 'unknown', 'lost', 'presentation_state', 'assignment_scope'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('equipment_items', $column),
                "equipment_items.{$column} should not exist; EQUIP-005 derives these on read.",
            );
            $this->assertFalse(
                Schema::hasColumn('equipment_checkouts', $column),
                "equipment_checkouts.{$column} should not exist; EQUIP-005 derives these on read.",
            );
        }
    }

    /**
     * A shift-assigned checkout answers to its shift's end (EQUIP-009).
     */
    public function test_a_shift_assigned_checkout_reads_overdue_after_its_shift_ends(): void
    {
        [$shift, $staff, , $operator, $equipment] = $this->scenario();
        $event = $shift->event;

        $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment,
            staff: $staff,
            actor: $operator,
            shift: $shift,
            checkedOutAt: Carbon::parse('2026-07-01 09:00:00'),
        )->checkout;

        $this->assertSame(EquipmentCheckout::SCOPE_SHIFT, $checkout->assignmentScope());

        $duringShift = EquipmentCheckoutPresentation::describe(
            $checkout,
            $shift,
            $event,
            Carbon::parse('2026-07-01 12:00:00'),
        );

        $this->assertSame(EquipmentCheckoutPresentation::STATE_OUT, $duringShift['state']);
        $this->assertFalse($duringShift['overdue']);
        $this->assertSame($shift->ends_at->toIso8601String(), $duringShift['due_at']);

        // One minute past the shift's end, and nothing about the record has
        // changed — only the clock.
        $afterShift = EquipmentCheckoutPresentation::describe(
            $checkout,
            $shift,
            $event,
            Carbon::parse('2026-07-01 16:01:00'),
        );

        $this->assertSame(EquipmentCheckoutPresentation::STATE_OVERDUE, $afterShift['state']);
        $this->assertTrue($afterShift['overdue']);
        $this->assertSame('Overdue', $afterShift['state_label']);
    }

    /**
     * An event-assigned checkout answers to the event's close, and is still on
     * time long after every shift has ended (EQUIP-009).
     *
     * This is the distinction the requirement exists for: a radio signed out for
     * the event is not late at four in the afternoon just because a shift
     * finished, and the desk must not be told it is.
     */
    public function test_an_event_assigned_checkout_answers_to_the_event_window(): void
    {
        [$shift, $staff, , $operator, $equipment] = $this->scenario();
        $event = $shift->event;
        $event->forceFill([
            'active_event_window_ends_at' => Carbon::parse('2026-07-05 12:00:00'),
        ])->save();

        $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment,
            staff: $staff,
            actor: $operator,
            checkedOutAt: Carbon::parse('2026-07-01 09:00:00'),
        )->checkout;

        $this->assertSame(EquipmentCheckout::SCOPE_EVENT, $checkout->assignmentScope());
        $this->assertNull($checkout->shift_id);

        // Long after the shift ended, and still not overdue, because it was
        // never issued against that shift.
        $afterTheShift = EquipmentCheckoutPresentation::describe(
            $checkout,
            null,
            $event->refresh(),
            Carbon::parse('2026-07-02 03:00:00'),
        );

        $this->assertSame(EquipmentCheckoutPresentation::STATE_OUT, $afterTheShift['state']);
        $this->assertFalse($afterTheShift['overdue']);

        $afterTheEvent = EquipmentCheckoutPresentation::describe(
            $checkout,
            null,
            $event->refresh(),
            Carbon::parse('2026-07-06 09:00:00'),
        );

        $this->assertTrue($afterTheEvent['overdue']);
        $this->assertSame(EquipmentCheckoutPresentation::STATE_OVERDUE, $afterTheEvent['state']);
        $this->assertUnusedShiftScope($shift);
    }

    /**
     * With no window end to measure against, the answer is Unknown (EQUIP-005).
     *
     * Reporting it as on time would be Meridian guessing and presenting the
     * guess as a fact. Unknown is the honest reading, and an operator can act on
     * it — they know to go and ask.
     */
    public function test_a_checkout_with_no_window_end_reads_unknown_rather_than_on_time(): void
    {
        [$shift, $staff, , $operator, $equipment] = $this->scenario();
        $event = $shift->event;
        $event->forceFill([
            'active_event_window_ends_at' => null,
            'ends_at' => null,
        ])->save();

        $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment,
            staff: $staff,
            actor: $operator,
            checkedOutAt: Carbon::parse('2026-07-01 09:00:00'),
        )->checkout;

        $derived = EquipmentCheckoutPresentation::describe(
            $checkout,
            null,
            $event->refresh(),
            Carbon::parse('2026-07-09 09:00:00'),
        );

        $this->assertSame(EquipmentCheckoutPresentation::STATE_UNKNOWN, $derived['state']);
        $this->assertSame('Unknown', $derived['state_label']);
        $this->assertNull($derived['due_at']);
        $this->assertFalse($derived['overdue']);
    }

    /** A returned checkout reads its stored condition and is not re-derived. */
    public function test_a_returned_checkout_reads_its_recorded_condition(): void
    {
        [$shift, $staff, , $operator, $equipment] = $this->scenario();

        $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment,
            staff: $staff,
            actor: $operator,
            shift: $shift,
            checkedOutAt: Carbon::parse('2026-07-01 09:00:00'),
        )->checkout;

        $returned = app(EquipmentCheckoutService::class)->returnEquipment(
            checkout: $checkout,
            actor: $operator,
            returnCondition: EquipmentItem::STATUS_MISSING,
            returnedAt: Carbon::parse('2026-07-01 15:00:00'),
        )->checkout;

        // Long past the shift's end, and still Missing rather than Overdue: the
        // record has an answer on file and nothing here improves on it.
        $derived = EquipmentCheckoutPresentation::describe(
            $returned,
            $shift,
            $shift->event,
            Carbon::parse('2026-07-08 09:00:00'),
        );

        $this->assertSame(EquipmentItem::STATUS_MISSING, $derived['state']);
        $this->assertFalse($derived['overdue']);
    }

    // ---------------------------------------------------------------
    // M18.24B — pooled and tracked equipment
    // ---------------------------------------------------------------

    /**
     * The migration writes down what the rows already are (EQUIP-010).
     *
     * Every equipment record that existed before pooling was one physical unit
     * checked out whole, so `individual` with quantity 1 is a restatement rather
     * than a conversion.
     */
    public function test_existing_equipment_is_individually_tracked_with_quantity_one(): void
    {
        $item = EquipmentItem::factory()->create();
        $checkout = EquipmentCheckout::factory()->create();

        $this->assertSame(EquipmentItem::TRACKING_INDIVIDUAL, $item->tracking);
        $this->assertSame(1, (int) $item->quantity_total);
        $this->assertFalse($item->isPooled());
        $this->assertSame(1, (int) $checkout->quantity);
    }

    /** Pooled availability falls as units go out and recovers as they come back (EQUIP-016). */
    public function test_pooled_availability_falls_and_recovers(): void
    {
        [, $staff, , $operator, , $department, $event] = $this->scenario();
        $pool = $this->pool($department, $event, 'Safety vest', 10);

        $this->assertSame(10, $pool->availableQuantity());

        $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $pool,
            staff: $staff,
            actor: $operator,
            quantity: 3,
        )->checkout;

        $this->assertSame(7, $pool->refresh()->availableQuantity());
        $this->assertSame(3, (int) $checkout->quantity);

        app(EquipmentCheckoutService::class)->returnEquipment(
            checkout: $checkout,
            actor: $operator,
            returnCondition: EquipmentItem::STATUS_RETURNED,
        );

        $this->assertSame(10, $pool->refresh()->availableQuantity());
    }

    /**
     * A pool is never stored `checked_out` (EQUIP-016; UI contract 9.6).
     *
     * A pool is not wholly held by one staff member, so there is no state that
     * describes it being out. Availability is the derivation; the record stays
     * Available and reads its remaining quantity.
     */
    public function test_a_pool_is_never_stored_checked_out(): void
    {
        [, $staff, , $operator, , $department, $event] = $this->scenario();
        $pool = $this->pool($department, $event, 'Safety vest', 4);

        app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $pool,
            staff: $staff,
            actor: $operator,
            quantity: 4,
        );

        $this->assertSame(EquipmentItem::STATUS_AVAILABLE, $pool->refresh()->status);
        $this->assertSame(0, $pool->availableQuantity());
        $this->assertFalse($pool->canBeCheckedOut());

        $this->assertDatabaseMissing('equipment_items', [
            'id' => $pool->id,
            'status' => EquipmentItem::STATUS_CHECKED_OUT,
        ]);
    }

    /**
     * Department stock can be signed out for the event (EQUIP-009).
     *
     * Found by running the desk rather than by reading the code. The checkout
     * inventory offers a department's stock — a pool almost always is stock,
     * scoped to the department rather than to an event — and the command
     * refused every one of them with "requires an event context", because it
     * derived the event from the item and department stock names none. The desk
     * offered what the node would not accept, which is exactly the client/server
     * disagreement CLIENT-006 exists to prevent.
     *
     * The event now comes from the desk making the handoff when the item does
     * not carry one. An item that does carry one still resolves to its own, so
     * this cannot be used to borrow another event's authority.
     */
    public function test_department_stock_can_be_checked_out_for_the_event(): void
    {
        [, $staff, , $operator, , $department, $event] = $this->scenario();
        $pool = EquipmentItem::factory()
            ->pooled(20)
            ->create([
                'organization_id' => $department->organization_id,
                // Department stock: no event of its own.
                'event_id' => null,
                'department_id' => $department->id,
                'name' => 'Water bottle',
            ]);

        $result = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $pool,
            staff: $staff,
            actor: $operator,
            quantity: 2,
            event: $event,
        );

        $this->assertSame((string) $event->id, (string) $result->checkout->event_id);
        $this->assertSame(EquipmentCheckout::SCOPE_EVENT, $result->checkout->assignmentScope());
        $this->assertSame(18, $pool->refresh()->availableQuantity());

        // And it comes back. The return path had the same gap for the same
        // reason — it weighed authority against the item's scope rather than
        // against the event the checkout was made under — so it refused every
        // return of the stock this service had just accepted.
        app(EquipmentCheckoutService::class)->returnEquipment(
            checkout: $result->checkout,
            actor: $operator,
            returnCondition: EquipmentItem::STATUS_RETURNED,
        );

        $this->assertSame(20, $pool->refresh()->availableQuantity());
    }

    /** With no event anywhere, the refusal still stands. */
    public function test_department_stock_with_no_event_context_is_still_refused(): void
    {
        [, $staff, , $operator, , $department] = $this->scenario();
        $stock = EquipmentItem::factory()->create([
            'organization_id' => $department->organization_id,
            'event_id' => null,
            'department_id' => $department->id,
            'name' => 'Clipboard',
            'asset_tag' => 'CLP-01',
        ]);

        $this->expectException(EquipmentCheckoutException::class);
        $this->expectExceptionMessage('requires an event context');

        app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $stock,
            staff: $staff,
            actor: $operator,
        );
    }

    /** A pooled checkout may come back in parts (data/API 10.13). */
    public function test_a_pooled_checkout_returns_in_parts(): void
    {
        [, $staff, , $operator, , $department, $event] = $this->scenario();
        $pool = $this->pool($department, $event, 'Safety vest', 10);

        $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $pool,
            staff: $staff,
            actor: $operator,
            quantity: 5,
        )->checkout;

        $partial = app(EquipmentCheckoutService::class)->returnEquipment(
            checkout: $checkout,
            actor: $operator,
            returnCondition: EquipmentItem::STATUS_RETURNED,
            quantity: 2,
        )->checkout;

        // Still open: the desk is owed three more, and closing it here would
        // lose that.
        $this->assertNull($partial->returned_at);
        $this->assertSame(2, (int) $partial->quantity_returned);
        $this->assertSame(3, $partial->quantityOutstanding());
        $this->assertSame(7, $pool->refresh()->availableQuantity());

        $closed = app(EquipmentCheckoutService::class)->returnEquipment(
            checkout: $partial,
            actor: $operator,
            returnCondition: EquipmentItem::STATUS_RETURNED,
            quantity: 3,
        )->checkout;

        $this->assertNotNull($closed->returned_at);
        $this->assertSame(0, $closed->quantityOutstanding());
        $this->assertSame(10, $pool->refresh()->availableQuantity());
    }

    /**
     * A damaged pooled return reduces the serviceable quantity and audits why
     * (EQUIP-017).
     *
     * Not a state change: three broken vests do not make a pool of forty
     * "Damaged". The loss lands on the total the availability derivation reads,
     * and the reason the operator gave rides on the audit entry.
     */
    public function test_a_damaged_pooled_return_reduces_the_pool_and_audits_the_reason(): void
    {
        [, $staff, , $operator, , $department, $event] = $this->scenario();
        $pool = $this->pool($department, $event, 'Safety vest', 10);

        $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $pool,
            staff: $staff,
            actor: $operator,
            quantity: 4,
        )->checkout;

        app(EquipmentCheckoutService::class)->returnEquipment(
            checkout: $checkout,
            actor: $operator,
            returnCondition: EquipmentItem::STATUS_DAMAGED,
            quantity: 3,
            reason: 'Torn on the fence line.',
        );

        $pool->refresh();

        $this->assertSame(EquipmentItem::STATUS_AVAILABLE, $pool->status);
        $this->assertSame(7, (int) $pool->quantity_total);
        // One unit is still out on the checkout, so seven total less one out.
        $this->assertSame(6, $pool->availableQuantity());

        $adjustment = AuditEvent::query()
            ->where('action', 'equipment_pool.adjusted')
            ->where('entity_id', (string) $pool->id)
            ->firstOrFail();

        $this->assertSame('Torn on the fence line.', $adjustment->reason);
        $this->assertSame(10, $adjustment->before_json['quantity_total']);
        $this->assertSame(7, $adjustment->after_json['quantity_total']);
        $this->assertSame(-3, $adjustment->after_json['adjusted_by']);
        $this->assertSame((string) $operator->id, (string) $adjustment->actor_user_id);
    }

    /** A pool cannot be shrunk below what is already out (EQUIP-016). */
    public function test_a_pool_total_cannot_fall_below_the_units_in_hand(): void
    {
        [, $staff, , $operator, , $department, $event] = $this->scenario();
        $pool = $this->pool($department, $event, 'Safety vest', 10);

        app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $pool,
            staff: $staff,
            actor: $operator,
            quantity: 6,
        );

        $this->expectException(EquipmentInventoryException::class);
        $this->expectExceptionMessage('6 unit(s) of this kind are still checked out');

        app(EquipmentInventoryService::class)->update($pool->refresh(), [
            'name' => 'Safety vest',
            'tracking' => EquipmentItem::TRACKING_POOLED,
            'quantity_total' => 2,
        ], $operator);
    }

    /** Handing out more than the pool holds is refused rather than going negative. */
    public function test_a_pool_refuses_a_checkout_larger_than_what_is_available(): void
    {
        [, $staff, , $operator, , $department, $event] = $this->scenario();
        $pool = $this->pool($department, $event, 'Safety vest', 2);

        $this->expectException(EquipmentCheckoutException::class);
        $this->expectExceptionMessage('Only 2 of "Safety vest" are available');

        app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $pool,
            staff: $staff,
            actor: $operator,
            quantity: 3,
        );
    }

    /**
     * Re-running an import updates a pool rather than standing a second one
     * beside it (data/API 10.13).
     *
     * A pool has no asset tag to be matched on, so it is matched by department,
     * name, and tracking kind. Two "Handheld radio" pools in one department
     * would leave neither with a correct availability.
     */
    public function test_re_importing_a_pooled_row_updates_it_rather_than_duplicating_it(): void
    {
        [, , , $operator, , $department, $event] = $this->scenario();

        $csv = implode("\n", [
            'name,tracking,quantity_total,asset_tag',
            'Handheld radio,pooled,40,',
            'Radio 20,individual,,RDO-20',
        ]);

        $first = app(EquipmentInventoryService::class)->import($department, $event, $csv, $operator);

        $this->assertSame(2, $first['imported']);
        $this->assertSame(0, $first['updated']);

        $revised = implode("\n", [
            'name,tracking,quantity_total,asset_tag',
            'Handheld radio,pooled,45,',
            'Radio 20,individual,,RDO-20',
        ]);

        $second = app(EquipmentInventoryService::class)->import($department, $event, $revised, $operator);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['updated']);
        // The tracked row is still skipped as a duplicate asset tag, which is
        // the behaviour that was already there and is still right.
        $this->assertSame(1, $second['skipped']);

        $pools = EquipmentItem::query()
            ->where('department_id', $department->id)
            ->pooled()
            ->get();

        $this->assertCount(1, $pools);
        $this->assertSame(45, (int) $pools->first()->quantity_total);
        $this->assertNull($pools->first()->asset_tag);
    }

    // ---------------------------------------------------------------
    // M18.24C — lookup
    // ---------------------------------------------------------------

    /** An exact asset tag resolves without a selection step (EQUIP-013). */
    public function test_an_exact_asset_tag_resolves_to_one_item(): void
    {
        [, , , $operator, $equipment, $department, $event] = $this->scenario();
        $lookup = app(EquipmentLookupService::class);

        $result = $lookup->resolve($lookup->candidates($event, $department), 'rdo-12');

        $this->assertSame(EquipmentLookupService::OUTCOME_RESOLVED, $result['outcome']);
        $this->assertSame((string) $equipment->id, (string) $result['item']->id);
    }

    /**
     * An asset tag from another department returns no match and does not reveal
     * that the item exists (EQUIP-015).
     *
     * The refusal is silence, not a different refusal. A distinct message for a
     * real tag would let a caller enumerate another department's inventory by
     * watching which values answer differently.
     */
    public function test_an_asset_tag_from_another_department_returns_no_match(): void
    {
        [, , , $operator, , $department, $event] = $this->scenario();
        $otherDepartment = Department::factory()->for($event->organization)->create(['name' => 'Gate']);

        EquipmentItem::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'department_id' => $otherDepartment->id,
            'name' => 'Gate radio',
            'asset_tag' => 'GATE-01',
            'status' => EquipmentItem::STATUS_AVAILABLE,
        ]);

        $lookup = app(EquipmentLookupService::class);
        $candidates = $lookup->candidates($event, $department);
        $found = $lookup->resolve($candidates, 'GATE-01');
        $invented = $lookup->resolve($candidates, 'NOT-A-TAG-AT-ALL');

        $this->assertSame(EquipmentLookupService::OUTCOME_NONE, $found['outcome']);
        $this->assertSame([], $found['matches']);
        // The two answers are the same shape, so neither says which of them was
        // a real tag.
        $this->assertSame($invented['outcome'], $found['outcome']);
        $this->assertStringNotContainsString('Gate radio', (string) $found['message']);
    }

    /** An ambiguous identifier resolves to nothing and says so (EQUIP-013). */
    public function test_an_ambiguous_identifier_resolves_to_nothing_and_says_so(): void
    {
        [, , , , , $department, $event] = $this->scenario();

        // A serial number reused as another unit's asset tag: two items, one
        // typed value, and no basis for choosing between them.
        EquipmentItem::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'department_id' => $department->id,
            'name' => 'Radio 13',
            'asset_tag' => 'RDO-13',
            'serial_number' => 'RDO-12',
            'status' => EquipmentItem::STATUS_AVAILABLE,
        ]);

        $lookup = app(EquipmentLookupService::class);
        $result = $lookup->resolve($lookup->candidates($event, $department), 'RDO-12');

        $this->assertSame(EquipmentLookupService::OUTCOME_AMBIGUOUS, $result['outcome']);
        $this->assertNull($result['item']);
        $this->assertCount(2, $result['matches']);
        $this->assertStringContainsString('matches more than one item', (string) $result['message']);
    }

    /** Lookup offers only what is available to hand out (EQUIP-015). */
    public function test_lookup_offers_only_equipment_available_to_hand_out(): void
    {
        [$shift, $staff, , $operator, $equipment, $department, $event] = $this->scenario();

        app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment,
            staff: $staff,
            actor: $operator,
            shift: $shift,
        );

        $lookup = app(EquipmentLookupService::class);
        $result = $lookup->resolve($lookup->candidates($event, $department), 'RDO-12');

        $this->assertSame(EquipmentLookupService::OUTCOME_NONE, $result['outcome']);
    }

    /** The lookup endpoint enforces the same department authority as checkout. */
    public function test_the_lookup_endpoint_refuses_a_caller_without_equipment_authority(): void
    {
        [, , , , , $department, $event] = $this->scenario();
        $outsider = User::factory()->create();
        $outsider->staffProfiles()->attach(Staff::factory()->create()->id);

        $this->actingAsClient($outsider)
            ->getJson("/api/events/{$event->id}/departments/{$department->id}/equipment-lookup?q=RDO-12")
            ->assertForbidden();
    }

    /** The endpoint returns the same resolution the service does. */
    public function test_the_lookup_endpoint_resolves_an_exact_tag(): void
    {
        [, , , $operator, $equipment, $department, $event] = $this->scenario();

        $this->actingAsClient($operator)
            ->getJson("/api/events/{$event->id}/departments/{$department->id}/equipment-lookup?q=RDO-12")
            ->assertOk()
            ->assertJsonPath('outcome', EquipmentLookupService::OUTCOME_RESOLVED)
            ->assertJsonPath('resolved.equipment_item_id', (string) $equipment->id)
            ->assertJsonPath('resolved.tracking', EquipmentItem::TRACKING_INDIVIDUAL);
    }

    /**
     * The desk carries the scoped inventory lookup resolves against offline
     * (EQUIP-015).
     */
    public function test_the_desk_read_carries_the_checkout_inventory(): void
    {
        [, , , $operator, , $department, $event] = $this->scenario();
        $this->pool($department, $event, 'Safety vest', 12);

        $payload = $this->actingAsClient($operator)
            ->getJson("/api/events/{$event->id}/departments/{$department->id}/logistics")
            ->assertOk()
            ->json();

        // Published once for the desk rather than once per workspace: an
        // available item is available to whoever is standing at it, and a
        // department with hundreds of tracked units and dozens of staff would
        // otherwise repeat the whole inventory in every workspace.
        $inventory = collect($payload['checkout_inventory']);

        $this->assertTrue($inventory->contains(fn (array $row): bool => $row['asset_tag'] === 'RDO-12'));

        $vest = $inventory->firstWhere('name', 'Safety vest');

        $this->assertSame(EquipmentItem::TRACKING_POOLED, $vest['tracking']);
        $this->assertSame('Pooled', $vest['tracking_label']);
        $this->assertSame(12, $vest['quantity_available']);
        $this->assertNull($vest['asset_tag']);
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: ShiftAssignment, 3: User, 4: EquipmentItem, 5: Department, 6: Event}
     */
    private function scenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create([
            'starts_at' => Carbon::parse('2026-06-30 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-05 08:00:00'),
        ]);
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $event->departmentAssignments()->create(['department_id' => $department->id]);
        $staff = Staff::factory()->create();

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Ranger Dirt Day Shift',
            'starts_at' => Carbon::parse('2026-07-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00'),
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $equipment = EquipmentItem::factory()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'department_id' => $department->id,
            'name' => 'Radio 12',
            'asset_tag' => 'RDO-12',
            'serial_number' => 'SN-0012',
            'status' => EquipmentItem::STATUS_AVAILABLE,
        ]);

        return [
            $shift,
            $staff,
            $assignment,
            $this->logisticsUserFor($department->defaultTeam),
            $equipment,
            $department,
            $event,
        ];
    }

    private function pool(
        Department $department,
        Event $event,
        string $name,
        int $quantityTotal,
    ): EquipmentItem {
        return EquipmentItem::factory()
            ->pooled($quantityTotal)
            ->create([
                'organization_id' => $department->organization_id,
                'event_id' => $event->id,
                'department_id' => $department->id,
                'name' => $name,
            ]);
    }

    private function logisticsUserFor(Team $team): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $departmentMembership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()
                ->where('code', 'department_logistics')
                ->firstOrFail()
                ->id,
        ]);

        return $user;
    }

    /**
     * The shift exists and was deliberately not used, which is what makes the
     * event-assigned reading meaningful rather than accidental.
     */
    private function assertUnusedShiftScope(Shift $shift): void
    {
        $this->assertSame(
            0,
            EquipmentCheckout::query()->where('shift_id', $shift->id)->count(),
        );
    }
}
