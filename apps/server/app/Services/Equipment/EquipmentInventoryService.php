<?php

namespace App\Services\Equipment;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product-path department equipment inventory setup (M11.18).
 *
 * Creates and maintains the `equipment_items` records that the existing
 * Logistics checkout/check-in workflow ({@see EquipmentCheckoutService})
 * consumes, so an inventory can be built before operations start
 * (EQUIP-001 through EQUIP-005, EQUIP-007; requirements 3.18, 7.13;
 * data/API 10.13).
 *
 * Scope boundaries this service deliberately keeps:
 * - inventory is department-scoped; department-to-department allotments are
 *   excluded from MVP by EQUIP-006 and are not modeled;
 * - full custody chains stay out of scope, so items carry a current state and
 *   checkout records, not a per-hand-off ledger;
 * - checkout state belongs to Logistics. Inventory setup never writes
 *   `checked_out`/`returned` and refuses to change state or archive an item
 *   while it has an open checkout;
 * - items are archived, never deleted, so operational history survives.
 */
final class EquipmentInventoryService
{
    /**
     * States a maintainer may set from inventory setup.
     *
     * `checked_out` and `returned` are produced by the Logistics workflow.
     * `missing`/`damaged` remain settable here because MVP equipment tracking
     * is explicitly manual and supports correction when physical handoffs
     * happen outside the system (requirements 3.18).
     *
     * @var list<string>
     */
    private const MAINTAINABLE_STATUSES = [
        EquipmentItem::STATUS_AVAILABLE,
        EquipmentItem::STATUS_MISSING,
        EquipmentItem::STATUS_DAMAGED,
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{name: string, asset_tag?: string|null, serial_number?: string|null, event_id?: string|null}  $attributes
     */
    public function create(
        Department $department,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): EquipmentItem {
        $values = $this->itemValues($department, $attributes);

        return DB::transaction(function () use ($department, $values, $actor, $sourceContext): EquipmentItem {
            $this->assertAssetTagAvailable($department, $values['asset_tag'], null);

            $item = EquipmentItem::query()->create([
                'organization_id' => $department->organization_id,
                'department_id' => $department->id,
                // New inventory always starts available; Logistics owns the
                // checkout lifecycle from there (EQUIP-002, EQUIP-003).
                'status' => EquipmentItem::STATUS_AVAILABLE,
                ...$values,
            ]);

            $this->audit->recordForEntity(
                entity: $item,
                action: 'equipment_item.created',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                eventId: $item->event_id !== null ? (string) $item->event_id : null,
                departmentId: (string) $department->id,
                after: $this->snapshot($item),
                sourceContext: $sourceContext,
            );

            return $item;
        });
    }

    /**
     * @param  array{name: string, asset_tag?: string|null, serial_number?: string|null, event_id?: string|null, status?: string|null}  $attributes
     */
    public function update(
        EquipmentItem $item,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): EquipmentItem {
        $department = $this->departmentFor($item);
        $values = $this->itemValues($department, $attributes);
        $requestedStatus = $attributes['status'] ?? null;

        return DB::transaction(function () use ($item, $department, $values, $requestedStatus, $actor, $sourceContext): EquipmentItem {
            $item = EquipmentItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($item->isArchived()) {
                throw new EquipmentInventoryException('Restore this equipment before editing it.');
            }

            $this->assertAssetTagAvailable($department, $values['asset_tag'], (string) $item->id);

            // Resolve the target state against the locked row, so an omitted
            // status keeps whatever Logistics last wrote rather than a value
            // read before the lock.
            $status = $this->statusValue($requestedStatus, $item);

            if ($status !== $item->status && $this->hasOpenCheckout($item)) {
                throw new EquipmentInventoryException(
                    'This equipment is checked out. Return it from the Logistics Window to change its state.',
                );
            }

            $before = $this->snapshot($item);
            $item->fill([...$values, 'status' => $status])->save();
            $item->refresh();

            $this->audit->recordForEntity(
                entity: $item,
                action: 'equipment_item.updated',
                actorUser: $actor,
                organizationId: (string) $item->organization_id,
                eventId: $item->event_id !== null ? (string) $item->event_id : null,
                departmentId: (string) $item->department_id,
                before: $before,
                after: $this->snapshot($item),
                sourceContext: $sourceContext,
            );

            return $item;
        });
    }

    /**
     * Retire an item from the pickable inventory without destroying its
     * checkout history.
     */
    public function archive(
        EquipmentItem $item,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): EquipmentItem {
        return DB::transaction(function () use ($item, $actor, $sourceContext): EquipmentItem {
            $item = EquipmentItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($item->isArchived()) {
                throw new EquipmentInventoryException('This equipment is already archived.');
            }

            if ($this->hasOpenCheckout($item)) {
                throw new EquipmentInventoryException(
                    'This equipment is checked out. Return it from the Logistics Window before archiving it.',
                );
            }

            return $this->transitionArchiveState($item, now(), 'equipment_item.archived', $actor, $sourceContext);
        });
    }

    public function restore(
        EquipmentItem $item,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): EquipmentItem {
        return DB::transaction(function () use ($item, $actor, $sourceContext): EquipmentItem {
            $item = EquipmentItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if (! $item->isArchived()) {
                throw new EquipmentInventoryException('This equipment is not archived.');
            }

            return $this->transitionArchiveState($item, null, 'equipment_item.restored', $actor, $sourceContext);
        });
    }

    /**
     * Bulk-create inventory from spreadsheet CSV text.
     *
     * Expected header columns: `name` (required), `asset_tag` (optional), and
     * `serial_number` (optional). Unknown columns are ignored. Every row is
     * processed independently so one bad row does not abort the import, and
     * rows whose asset tag already exists are skipped rather than duplicated,
     * so re-running the same file is safe. Event scope comes from the request,
     * not from the file, so an import cannot place equipment in another
     * department or organization (EQUIP-006).
     *
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     rows: list<array{line: int, name: string, asset_tag: string|null, status: string, reason: string|null}>
     * }
     */
    public function import(
        Department $department,
        ?Event $event,
        string $csv,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): array {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

        if ($lines === [] || trim($lines[0]) === '') {
            throw new EquipmentInventoryException('The CSV is empty.');
        }

        $header = array_map(
            static fn (string $column): string => Str::lower(trim($column)),
            str_getcsv(array_shift($lines)),
        );

        $nameIndex = array_search('name', $header, true);

        if ($nameIndex === false) {
            throw new EquipmentInventoryException('The CSV must include a "name" header column.');
        }

        $assetTagIndex = array_search('asset_tag', $header, true);
        $serialNumberIndex = array_search('serial_number', $header, true);

        $rows = [];
        $imported = 0;

        foreach ($lines as $offset => $line) {
            $lineNumber = $offset + 2;

            if (trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line);
            $name = trim((string) ($columns[$nameIndex] ?? ''));
            $assetTag = $assetTagIndex === false
                ? null
                : $this->nullableTrim((string) ($columns[$assetTagIndex] ?? ''));

            if ($name === '') {
                $rows[] = $this->importRow($lineNumber, $name, $assetTag, 'skipped', 'Missing name.');

                continue;
            }

            $serialNumber = $serialNumberIndex === false
                ? null
                : $this->nullableTrim((string) ($columns[$serialNumberIndex] ?? ''));

            try {
                $this->create($department, [
                    'name' => $name,
                    'asset_tag' => $assetTag,
                    'serial_number' => $serialNumber,
                    'event_id' => $event === null ? null : (string) $event->id,
                ], $actor, $sourceContext);
            } catch (EquipmentInventoryException $exception) {
                $rows[] = $this->importRow($lineNumber, $name, $assetTag, 'skipped', $exception->getMessage());

                continue;
            }

            $imported++;
            $rows[] = $this->importRow($lineNumber, $name, $assetTag, 'imported', null);
        }

        $skipped = count($rows) - $imported;

        $this->audit->recordForEntity(
            entity: $department,
            action: 'equipment_inventory.imported',
            actorUser: $actor,
            organizationId: (string) $department->organization_id,
            eventId: $event === null ? null : (string) $event->id,
            departmentId: (string) $department->id,
            after: [
                'imported' => $imported,
                'skipped' => $skipped,
            ],
            sourceContext: $sourceContext,
        );

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'rows' => $rows,
        ];
    }

    /**
     * Validate and normalize product-path inventory attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{name: string, asset_tag: string|null, serial_number: string|null, event_id: string|null}
     */
    private function itemValues(Department $department, array $attributes): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            throw new EquipmentInventoryException('Equipment name is required.');
        }

        if (mb_strlen($name) > 255) {
            throw new EquipmentInventoryException('Equipment name must be 255 characters or fewer.');
        }

        $eventId = $attributes['event_id'] ?? null;

        if ($eventId !== null && $eventId !== '') {
            $event = Event::query()->find($eventId);

            if ($event === null || (string) $event->organization_id !== (string) $department->organization_id) {
                throw new EquipmentInventoryException('Event must belong to the department organization.');
            }

            $eventId = (string) $event->id;
        } else {
            $eventId = null;
        }

        return [
            'name' => $name,
            'asset_tag' => $this->nullableTrim($attributes['asset_tag'] ?? null),
            'serial_number' => $this->nullableTrim($attributes['serial_number'] ?? null),
            'event_id' => $eventId,
        ];
    }

    private function statusValue(mixed $status, EquipmentItem $item): string
    {
        if ($status === null || $status === '') {
            return $item->status;
        }

        $status = (string) $status;

        if (! in_array($status, self::MAINTAINABLE_STATUSES, true)) {
            throw new EquipmentInventoryException(
                'Inventory setup can only set Available, Missing, or Damaged. '
                .'Checked out and Returned come from the Logistics Window.',
            );
        }

        return $status;
    }

    /**
     * Asset tags identify a physical item, so they must stay unique inside the
     * department that owns the inventory. This is what lets a re-run of the
     * same import skip rows instead of duplicating equipment.
     */
    private function assertAssetTagAvailable(
        Department $department,
        ?string $assetTag,
        ?string $ignoreItemId,
    ): void {
        if ($assetTag === null) {
            return;
        }

        $exists = EquipmentItem::query()
            ->where('department_id', $department->id)
            ->where('asset_tag', $assetTag)
            ->when($ignoreItemId !== null, fn ($query) => $query->whereKeyNot($ignoreItemId))
            ->exists();

        if ($exists) {
            throw new EquipmentInventoryException(
                "Asset tag \"{$assetTag}\" already exists in this department.",
            );
        }
    }

    private function hasOpenCheckout(EquipmentItem $item): bool
    {
        return EquipmentCheckout::query()
            ->where('equipment_item_id', $item->id)
            ->whereNull('returned_at')
            ->exists();
    }

    private function transitionArchiveState(
        EquipmentItem $item,
        ?Carbon $archivedAt,
        string $action,
        User $actor,
        string $sourceContext,
    ): EquipmentItem {
        $before = $this->snapshot($item);
        $item->forceFill(['archived_at' => $archivedAt])->save();
        $item->refresh();

        $this->audit->recordForEntity(
            entity: $item,
            action: $action,
            actorUser: $actor,
            organizationId: (string) $item->organization_id,
            eventId: $item->event_id !== null ? (string) $item->event_id : null,
            departmentId: $item->department_id !== null ? (string) $item->department_id : null,
            before: $before,
            after: $this->snapshot($item),
            sourceContext: $sourceContext,
        );

        return $item;
    }

    private function departmentFor(EquipmentItem $item): Department
    {
        $item->loadMissing('department');

        if ($item->department === null) {
            throw new EquipmentInventoryException(
                'This equipment is not owned by a department and can only be repaired in God Mode.',
            );
        }

        return $item->department;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{line: int, name: string, asset_tag: string|null, status: string, reason: string|null}
     */
    private function importRow(
        int $line,
        string $name,
        ?string $assetTag,
        string $status,
        ?string $reason,
    ): array {
        return [
            'line' => $line,
            'name' => $name,
            'asset_tag' => $assetTag,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(EquipmentItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'organization_id' => (string) $item->organization_id,
            'event_id' => $item->event_id !== null ? (string) $item->event_id : null,
            'department_id' => $item->department_id !== null ? (string) $item->department_id : null,
            'name' => $item->name,
            'asset_tag' => $item->asset_tag,
            'serial_number' => $item->serial_number,
            'status' => $item->status,
            'archived_at' => $item->archived_at?->toIso8601String(),
        ];
    }
}
