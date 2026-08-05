<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Services\Equipment\EquipmentInventoryService;
use Database\Seeders\Support\ScenarioContext;
use Illuminate\Database\Seeder;

/**
 * The department inventories, in every state a maintainer has to deal with.
 *
 * The states are not decoration. Each one changes what the equipment page and
 * the Logistics Desk will let somebody do, and three of them are refusals: an
 * item with an open checkout cannot change state or be archived, an archived
 * item is off the assignable list until it is restored, and an item written off
 * as Missing is still owed to the department while no longer being a reason to
 * hold its holder on site (SLB-018). That last one is the subtlest rule at the
 * desk and the easiest to regress, so the scenario always has a written-off
 * radio sitting in somebody's hands.
 *
 * The checkouts themselves belong to the attendance seeder, which runs next and
 * hands equipment out as part of checking people in. That is the order the desk
 * does it in, and a checkout written here instead would not carry the shift it
 * was signed out against — which is the difference between kit that comes back
 * when the shift ends and kit that is out until its holder leaves site.
 */
class EquipmentScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $context = new ScenarioContext;
        $inventory = app(EquipmentInventoryService::class);

        $rangers = $context->department('RANGERS');
        $dana = $context->user('dana');

        // The pool the desk hands out from. Enough that checking two out still
        // leaves the checkout dialog with something to offer.
        foreach (['Radio 11', 'Radio 12', 'Radio 13', 'Radio 14', 'Radio 15'] as $index => $name) {
            $this->item($inventory, $rangers, $dana, [
                'name' => $name,
                'asset_tag' => sprintf('RDO-%02d', 11 + $index),
                'serial_number' => sprintf('SN-RDO-%04d', 1100 + $index),
            ]);
        }

        foreach (['Vest 1', 'Vest 2', 'Vest 3'] as $index => $name) {
            $this->item($inventory, $rangers, $dana, [
                'name' => $name,
                'asset_tag' => sprintf('VST-%02d', $index + 1),
            ]);
        }

        /*
         * A pooled kind, so the checkout dialog's two presentations both have
         * something in them (EQUIP-010, EQUIP-014; UI contract 9.6A). Tracked
         * units are found by lookup and pooled kinds by quantity, and a
         * scenario with only the first kind never renders the second.
         *
         * Thirty is chosen so handing out a handful still leaves the number
         * visibly moving rather than emptying, which is what makes the derived
         * availability (EQUIP-016) legible to somebody clicking through it.
         */
        $this->pool($inventory, $rangers, $dana, 'Hi-vis vest (pooled)', 30);
        $this->pool($inventory, $rangers, $dana, 'Water bottle', 60);

        $this->item($inventory, $rangers, $dana, [
            'name' => 'Perimeter Flag Set',
            'asset_tag' => 'FLG-01',
        ]);

        /*
         * Event-assigned rather than department-assigned, which is EQUIP-009's
         * distinction and not a detail: a checkout with no shift behind it is
         * only allowed against an item that names an event, so this is the kit
         * somebody signs out for the week rather than for a shift.
         *
         * The attendance seeder puts this one in Quinn's hands and then writes
         * it off without taking it back, which is the SLB-018 exception on
         * screen: the department is still owed it, it stays listed in his
         * workspace, and it stops being a reason to hold him on site. The
         * write-off happens after the checkout because an item already marked
         * missing cannot be checked out at all.
         */
        $this->item($inventory, $rangers, $dana, [
            'name' => 'Radio 09',
            'asset_tag' => 'RDO-09',
            'event_id' => (string) $context->event()->id,
        ]);

        // A second piece of event kit, left available, so the event-scoped
        // checkout path has something to exercise that is not already gone.
        $this->item($inventory, $rangers, $dana, [
            'name' => 'Radio 10',
            'asset_tag' => 'RDO-10',
            'event_id' => (string) $context->event()->id,
        ]);

        // Damaged and awaiting repair: on the inventory, off the assignable list.
        $damaged = $this->item($inventory, $rangers, $dana, [
            'name' => 'Radio 08',
            'asset_tag' => 'RDO-08',
        ]);

        if ($damaged->status !== EquipmentItem::STATUS_DAMAGED) {
            // `update` revalidates the whole item rather than patching one
            // field, so the name and tag travel with the state change.
            $inventory->update($damaged, [
                'name' => $damaged->name,
                'asset_tag' => $damaged->asset_tag,
                'status' => EquipmentItem::STATUS_DAMAGED,
            ], $dana);
        }

        // Archived, so the Active/Archived filter has both sides and the restore
        // path has a subject.
        $retired = $this->item($inventory, $rangers, $dana, [
            'name' => 'Radio 01',
            'asset_tag' => 'RDO-01',
            'serial_number' => 'SN-RDO-0001',
        ]);

        if (! $retired->isArchived()) {
            $inventory->archive($retired, $dana);
        }

        // The other two departments carry their own inventory, so equipment is
        // visibly department-scoped rather than looking like one shared pool.
        $gate = $context->department('GATE');

        foreach (['Gate Scanner 1', 'Gate Scanner 2'] as $index => $name) {
            $this->item($inventory, $gate, $context->user('gabe'), [
                'name' => $name,
                'asset_tag' => sprintf('SCN-%02d', $index + 1),
            ]);
        }

        $dpw = $context->department('DPW');

        foreach (['Pallet Jack', 'Light Tower'] as $index => $name) {
            $this->item($inventory, $dpw, $context->user('dex'), [
                'name' => $name,
                'asset_tag' => sprintf('DPW-%02d', $index + 1),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(
        EquipmentInventoryService $inventory,
        Department $department,
        User $actor,
        array $attributes,
    ): EquipmentItem {
        $existing = EquipmentItem::query()
            ->where('department_id', $department->id)
            ->where('asset_tag', $attributes['asset_tag'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $inventory->create(
            $department,
            ['event_id' => null, ...$attributes],
            $actor,
        );
    }

    /**
     * A pooled kind, matched on department and name because it has no asset tag
     * to be matched on (data/API 10.13).
     *
     * Same idempotency rule the import path uses, for the same reason: a re-seed
     * must leave one pool of thirty vests rather than two of thirty each.
     */
    private function pool(
        EquipmentInventoryService $inventory,
        Department $department,
        User $actor,
        string $name,
        int $quantityTotal,
    ): EquipmentItem {
        $existing = EquipmentItem::query()
            ->where('department_id', $department->id)
            ->pooled()
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $inventory->create($department, [
            'name' => $name,
            'tracking' => EquipmentItem::TRACKING_POOLED,
            'quantity_total' => $quantityTotal,
            'event_id' => null,
        ], $actor);
    }
}
