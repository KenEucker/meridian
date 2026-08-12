<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\OrganizationModule;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\Node\SyncConflictService;
use App\Services\Offline\OfflineWriteCommand;
use Illuminate\Http\Request;

/**
 * A queued offline write that landed on an inactive module (MOD-017; technical
 * spec 15A.8; data/API 7.5, 7.6; M19.17).
 *
 * The requirement is one sentence and both halves of it matter: such a write is
 * "refused and recorded as a sync conflict for God Mode resolution, rather than
 * silently dropped or silently applied". The refusal is
 * {@see \App\Http\Middleware\EnforceActiveModule}'s and is unchanged — the same
 * `404` naming the same module every other caller gets, so MOD-012's identical
 * refusal is not quietly made conditional on how the request arrived. This is
 * the recording half, and it runs on the way to that refusal.
 *
 * **Which requests it records.** Only the data/API 7.2 offline writes, and only
 * where the request carries the device-generated key those writes are queued
 * under ({@see OfflineWriteCommand}). That key is the evidence that this is a
 * replay rather than somebody at a desk: a live request refused for an inactive
 * module is a client that offered a surface it should not have (M19.16), which is
 * a defect to fix rather than a conflict for an operator to resolve, and a queue
 * filled with those would bury the ones that matter.
 *
 * **What it writes.** One open conflict per queued write, keyed by that device
 * key so a repeated delivery meets the row it already has. `local_value_json` is
 * the write as the device sent it, bounded — a Field Report's photo bytes are
 * megabytes and would turn the queue into a blob store — and `remote_value_json`
 * is the module state as this node holds it, which is the other side of the
 * disagreement and the thing an operator has to decide about.
 *
 * **What it does not write.** No audit event. The refusal is not a change to
 * anything, the conflict row is the record, and resolving it later is audited by
 * {@see \App\Services\Node\SyncConflictResolver} the way every other resolution
 * is.
 *
 * The event-window rule makes all of this rare by construction (MOD-010):
 * module state cannot change during an active event, which is when offline
 * queues are deepest. It is not impossible, which is why it is built.
 */
class ModuleInactiveWriteRecorder
{
    /**
     * Request fields never copied onto a conflict row.
     *
     * `bytes_base64` is a photo. The rest of a Field Report photo upload — its
     * id, the report it belongs to, the checksum, the device and the moment —
     * describes the refused work perfectly well without it, and the bytes are
     * still on the device that is holding the queue entry.
     *
     * @var list<string>
     */
    private const OMITTED_FIELDS = ['bytes_base64'];

    /**
     * How much of any one field is kept, so a long Field Report body is
     * readable in the queue without the queue becoming where it lives.
     */
    private const FIELD_LIMIT = 2000;

    public function __construct(private readonly SyncConflictService $conflicts) {}

    /**
     * Record the refusal, or answer null when this request is not a queued
     * offline write the node can describe.
     */
    public function record(Request $request, ModuleInactiveException $refusal): ?SyncConflict
    {
        $command = OfflineWriteCommand::forRoute($request->route()?->getName());

        if ($command === null) {
            return null;
        }

        $key = $command->keyOf($request);
        $subject = $command->subjectOf($request);
        $entityType = $command->entityType();

        /*
         * All three are needed and none of them is guessed at. Without the key
         * this is a live request; without a subject or an entity type the node
         * cannot say what the refused work was about, and a queue row that
         * names nothing is worse than the refusal the caller already has.
         */
        if ($key === null || $subject === null || $entityType === null) {
            return null;
        }

        return $this->conflicts->recordDeviceWrite(
            originOperationUuid: $key,
            entityType: $entityType,
            entityId: $subject,
            reason: sprintf(
                '%s was queued on a device and reached this node after %s was switched off, '
                .'so it was refused rather than applied.',
                $command->value,
                $refusal->module->label(),
            ),
            conflictType: SyncConflict::TYPE_MODULE_INACTIVE,
            localValue: $this->deviceWrite($request, $command, $key),
            remoteValue: $this->moduleState($refusal),
        );
    }

    /**
     * The write as the device sent it.
     *
     * @return array<string, mixed>
     */
    private function deviceWrite(Request $request, OfflineWriteCommand $command, string $key): array
    {
        $user = $request->user();

        return [
            'command' => $command->value,
            'operation_uuid' => $key,
            'submitted_by_user_id' => $user instanceof User ? (string) $user->getKey() : null,
            'fields' => $this->fields($request),
        ];
    }

    /**
     * The module state this node holds, which is the version the refusal was
     * decided from.
     *
     * Read from `organization_modules` rather than restated from the exception,
     * so the row shows an operator the two halves of MOD-005 separately: a
     * module the platform revoked and one the organization switched off are the
     * same absence to the device and different work to whoever resolves this.
     * A missing row is the MOD-009 default and is reported as such rather than
     * as false, because "never written" is not "switched off".
     *
     * @return array<string, mixed>
     */
    private function moduleState(ModuleInactiveException $refusal): array
    {
        $state = $refusal->organizationId === null
            ? null
            : OrganizationModule::query()
                ->where('organization_id', $refusal->organizationId)
                ->where('module_key', $refusal->module->value)
                ->first();

        return [
            'organization_id' => $refusal->organizationId,
            'module' => $refusal->module->value,
            'module_label' => $refusal->module->label(),
            'entitled' => $state?->entitled,
            'enabled' => $state?->enabled,
            'active' => false,
            'state_recorded' => $state !== null,
        ];
    }

    /**
     * The request body, bounded.
     *
     * @return array<string, mixed>
     */
    private function fields(Request $request): array
    {
        $fields = [];

        foreach ($request->all() as $name => $value) {
            if (! is_string($name) || in_array($name, self::OMITTED_FIELDS, true)) {
                continue;
            }

            $fields[$name] = match (true) {
                is_string($value) => mb_substr($value, 0, self::FIELD_LIMIT),
                is_scalar($value), $value === null => $value,
                // A nested structure is reported as its shape rather than
                // copied: nothing in the 7.2 list sends one, and a queue row is
                // not the place to find out what a client that does is sending.
                default => '['.gettype($value).']',
            };
        }

        return $fields;
    }
}
