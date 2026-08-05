<?php

namespace App\Services\Equipment;

use App\Models\Department;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\DepartmentOperationalAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Finding the equipment an operator is about to hand over (M18.24C; EQUIP-012,
 * EQUIP-013, EQUIP-015; SLB-011, SLB-012; data/API 10.13).
 *
 * The list this replaces was the problem. A department's tracked units were
 * rendered one checkbox per unit, which reads fine for a department with six
 * radios and is unusable for one with four hundred — EQUIP-012 says so
 * outright: "A department's individually tracked equipment shall not be
 * presented as a list of every unit for the operator to read through."
 *
 * So lookup, and three rules that are the whole of it:
 *
 *  1. **Scope is the boundary, and it is silent.** Candidates are the operator's
 *     own department's active inventory within the event, and only what is
 *     available to hand out. An asset tag belonging to another department does
 *     not come back as "not yours" — it comes back as no match, because
 *     EQUIP-015 says lookup "shall not disclose the existence of equipment
 *     outside it", and a distinct refusal for a real tag is a disclosure.
 *  2. **An exact identifier resolves without asking.** A value matching exactly
 *     one asset tag or serial number is that item (EQUIP-013). This is what
 *     makes a barcode scanner acting as a keyboard finish a handoff: the scan
 *     lands in the field, the item is added, and nobody touches the screen.
 *  3. **Ambiguity is reported, never guessed.** Two matches, or none, is an
 *     answer the operator gets told. Picking the first of two would be Meridian
 *     deciding which radio somebody is holding.
 *
 * Name matching is a contains-search and never resolves on its own, however few
 * hits it has. "Radio" narrowing to one row today and two rows tomorrow would
 * make the scanner path behave differently depending on the inventory, and the
 * one thing that path must be is predictable.
 */
final class EquipmentLookupService
{
    /** Exactly one identifier match: add it and move on (EQUIP-013). */
    public const OUTCOME_RESOLVED = 'resolved';

    /** Several candidates; the operator chooses. */
    public const OUTCOME_MULTIPLE = 'multiple';

    /** An identifier matching more than one item — reported, not guessed. */
    public const OUTCOME_AMBIGUOUS = 'ambiguous';

    /** Nothing in scope matches. */
    public const OUTCOME_NONE = 'none';

    /** No query yet; the pooled kinds are still worth showing. */
    public const OUTCOME_EMPTY_QUERY = 'empty_query';

    /**
     * How many name matches are worth returning.
     *
     * A cap rather than a page, because this is a type-ahead beside somebody
     * waiting at a desk. An operator whose search returns more than this has not
     * searched for anything yet.
     */
    public const MATCH_LIMIT = 10;

    public function __construct(private readonly DepartmentOperationalAccess $access) {}

    public function canLookUp(User $user, Event $event, Department $department): bool
    {
        return $this->access->canManageEquipment($user, $event, $department);
    }

    /**
     * The department's inventory that is available to hand out, in this event.
     *
     * Event scope follows the same rule the checkout service applies: an item
     * scoped to another event is out of scope, and an item scoped to no event is
     * department stock that this event's desk may hand out.
     *
     * @return Collection<int, EquipmentItem>
     */
    public function candidates(Event $event, Department $department): Collection
    {
        return EquipmentItem::query()
            ->where('department_id', $department->id)
            ->active()
            ->where(fn ($query) => $query
                ->whereNull('event_id')
                ->orWhere('event_id', $event->id))
            ->orderBy('name')
            ->get()
            ->filter(fn (EquipmentItem $item): bool => $item->canBeCheckedOut())
            ->values();
    }

    /**
     * Resolve one typed or scanned value against a candidate set.
     *
     * The candidates are passed in rather than queried here so the same rules
     * answer for a node read and for the copy a device already holds
     * (EQUIP-015). There is one resolution, and connectivity does not change it.
     *
     * @param  Collection<int, EquipmentItem>  $candidates
     * @return array{outcome: string, item: EquipmentItem|null, matches: list<EquipmentItem>, message: string|null}
     */
    public function resolve(Collection $candidates, string $query): array
    {
        $needle = Str::lower(trim($query));

        if ($needle === '') {
            return [
                'outcome' => self::OUTCOME_EMPTY_QUERY,
                'item' => null,
                'matches' => [],
                'message' => null,
            ];
        }

        $identifierMatches = $candidates
            ->filter(fn (EquipmentItem $item): bool => $this->matchesIdentifier($item, $needle))
            ->values();

        if ($identifierMatches->count() === 1) {
            return [
                'outcome' => self::OUTCOME_RESOLVED,
                'item' => $identifierMatches->first(),
                'matches' => [$identifierMatches->first()],
                'message' => null,
            ];
        }

        if ($identifierMatches->count() > 1) {
            return [
                'outcome' => self::OUTCOME_AMBIGUOUS,
                'item' => null,
                'matches' => $identifierMatches->take(self::MATCH_LIMIT)->values()->all(),
                'message' => "\"{$query}\" matches more than one item. Choose which one is being handed over.",
            ];
        }

        $matches = $candidates
            ->filter(fn (EquipmentItem $item): bool => Str::contains(Str::lower((string) $item->name), $needle))
            ->take(self::MATCH_LIMIT)
            ->values();

        if ($matches->isEmpty()) {
            return [
                'outcome' => self::OUTCOME_NONE,
                'item' => null,
                'matches' => [],
                'message' => "No equipment available to hand out matches \"{$query}\".",
            ];
        }

        return [
            'outcome' => self::OUTCOME_MULTIPLE,
            'item' => null,
            'matches' => $matches->all(),
            'message' => null,
        ];
    }

    /**
     * The payload shape both the lookup endpoint and the desk's cached
     * checkout inventory publish.
     *
     * Serial number is included because lookup matches on it and an operator
     * comparing two similarly named units needs to see what they matched. It is
     * already inside the department the caller administers equipment for, so it
     * discloses nothing the same caller cannot read on the inventory page.
     *
     * @return array<string, mixed>
     */
    public function payload(EquipmentItem $item): array
    {
        return [
            'equipment_item_id' => (string) $item->id,
            'name' => $item->name,
            'tracking' => $item->tracking,
            'tracking_label' => EquipmentItem::trackingLabel($item->tracking),
            'asset_tag' => $item->asset_tag,
            'serial_number' => $item->serial_number,
            'status' => $item->status,
            'status_label' => EquipmentItem::statusLabel($item->status),
            'quantity_total' => (int) $item->quantity_total,
            'quantity_available' => $item->availableQuantity(),
        ];
    }

    private function matchesIdentifier(EquipmentItem $item, string $needle): bool
    {
        foreach ([$item->asset_tag, $item->serial_number] as $identifier) {
            if ($identifier !== null && $identifier !== '' && Str::lower($identifier) === $needle) {
                return true;
            }
        }

        return false;
    }
}
