<?php

declare(strict_types=1);

namespace App\Services\SystemConfig;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\SystemConfigOverride;
use App\Models\User;
use App\Services\Audit\AuditService;
use InvalidArgumentException;

/**
 * The only write path for system configuration overrides (technical spec
 * 22A.5, 22A.6; SYS-005 through SYS-010, SYS-015).
 *
 * Every write validates the proposed value against the catalogue entry's
 * declared type, refuses variables that are not editable (bootstrap-locked,
 * read-only, managed, or unmapped), and records an audit event with redacted
 * values — a secret's plaintext never reaches the audit log (SYS-015).
 */
class SystemConfigOverrideStore
{
    public const AUDIT_CREATED = 'system_config_override.created';

    public const AUDIT_UPDATED = 'system_config_override.updated';

    public const AUDIT_DISABLED = 'system_config_override.disabled';

    public const AUDIT_ENABLED = 'system_config_override.enabled';

    public const AUDIT_REMOVED = 'system_config_override.removed';

    public const REDACTED = '[redacted]';

    public function __construct(
        private readonly EnvExampleCatalog $catalog,
        private readonly SystemConfigValueCodec $codec,
        private readonly AuditService $audit,
    ) {}

    /**
     * Create or replace the override for a variable from operator input.
     */
    public function put(
        Node $node,
        string $name,
        string $input,
        ?User $actor,
        ?string $changeReason,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): SystemConfigOverride {
        $entry = $this->editableEntry($name);

        if ($entry->secret && trim($changeReason ?? '') === '') {
            throw new InvalidArgumentException(
                "Changing {$name} requires a change reason because it is a secret.",
            );
        }

        $typed = $this->codec->fromInput($entry, $input);

        $existing = $this->overrideFor($node, $name);
        $previousDisplay = $this->redactedValue($existing?->is_secret ?? $entry->secret, $existing?->value_json);

        $override = SystemConfigOverride::query()->updateOrCreate(
            ['node_id' => $node->getKey(), 'name' => $name],
            [
                'type' => $entry->type,
                'value_json' => $entry->secret ? null : $this->codec->encode($typed),
                'secret_value' => $entry->secret ? (is_string($typed) ? $typed : $this->codec->encode($typed)) : null,
                'is_secret' => $entry->secret,
                'is_active' => true,
                'change_reason' => $changeReason,
                'updated_by_user_id' => $actor?->getKey(),
            ] + ($existing === null ? ['created_by_user_id' => $actor?->getKey()] : []),
        );

        $this->audit->recordForEntity(
            entity: $override,
            action: $existing === null ? self::AUDIT_CREATED : self::AUDIT_UPDATED,
            actorUser: $actor,
            actorNode: $node,
            before: $existing === null ? null : [
                'name' => $name,
                'value' => $previousDisplay,
                'source' => 'database_override',
                'secret' => $existing->is_secret,
            ],
            after: [
                'name' => $name,
                'value' => $this->redactedValue($entry->secret, $entry->secret ? null : $this->codec->encode($typed)),
                'source' => 'database_override',
                'secret' => $entry->secret,
                'activation' => $entry->activation(),
            ],
            reason: $changeReason,
            sourceContext: $sourceContext,
        );

        return $override;
    }

    public function setActive(
        Node $node,
        string $name,
        bool $active,
        ?User $actor,
        ?string $changeReason,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): SystemConfigOverride {
        $override = $this->requireOverride($node, $name);

        $override->forceFill([
            'is_active' => $active,
            'change_reason' => $changeReason,
            'updated_by_user_id' => $actor?->getKey(),
        ])->save();

        $this->audit->recordForEntity(
            entity: $override,
            action: $active ? self::AUDIT_ENABLED : self::AUDIT_DISABLED,
            actorUser: $actor,
            actorNode: $node,
            before: ['name' => $name, 'active' => ! $active, 'secret' => $override->is_secret],
            after: ['name' => $name, 'active' => $active, 'secret' => $override->is_secret],
            reason: $changeReason,
            sourceContext: $sourceContext,
        );

        return $override;
    }

    /**
     * Removing an override restores the environment/default value on the next
     * boot (SYS-009).
     */
    public function remove(
        Node $node,
        string $name,
        ?User $actor,
        ?string $changeReason,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): void {
        $override = $this->requireOverride($node, $name);

        $this->audit->recordForEntity(
            entity: $override,
            action: self::AUDIT_REMOVED,
            actorUser: $actor,
            actorNode: $node,
            before: [
                'name' => $name,
                'value' => $this->redactedValue($override->is_secret, $override->value_json),
                'source' => 'database_override',
                'secret' => $override->is_secret,
            ],
            after: ['name' => $name, 'source' => 'environment_or_default'],
            reason: $changeReason,
            sourceContext: $sourceContext,
        );

        $override->delete();
    }

    public function overrideFor(Node $node, string $name): ?SystemConfigOverride
    {
        return SystemConfigOverride::query()
            ->where('node_id', $node->getKey())
            ->where('name', $name)
            ->first();
    }

    /**
     * Validate operator input without writing anything (SYS-006).
     */
    public function validateInput(string $name, string $input): mixed
    {
        return $this->codec->fromInput($this->editableEntry($name), $input);
    }

    private function editableEntry(string $name): CatalogEntry
    {
        $entry = $this->catalog->entry($name);

        if ($entry === null) {
            throw new InvalidArgumentException("{$name} is not in the configuration catalogue.");
        }

        if (! $entry->editable()) {
            throw new InvalidArgumentException(match (true) {
                $entry->bootstrapLocked => "{$name} is bootstrap-locked and cannot be overridden from the database.",
                $entry->managedLabel !== null => "{$name} is managed by {$entry->managedLabel} and cannot be edited here.",
                ! $entry->mapped() => "{$name} has no Laravel configuration mapping and is read-only.",
                default => "{$name} is read-only.",
            });
        }

        return $entry;
    }

    private function requireOverride(Node $node, string $name): SystemConfigOverride
    {
        $override = $this->overrideFor($node, $name);

        if ($override === null) {
            throw new InvalidArgumentException("No override exists for {$name} on this node.");
        }

        return $override;
    }

    private function redactedValue(bool $secret, ?string $encoded): ?string
    {
        if ($secret) {
            return self::REDACTED;
        }

        return $encoded;
    }
}
