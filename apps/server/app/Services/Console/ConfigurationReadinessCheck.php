<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Models\Node;
use App\Models\Organization;
use App\Services\EventMode\EventModeCheck;
use App\Services\EventMode\EventModeGuard;
use App\Services\Node\NodeConfigResolver;
use App\Services\Node\NodeKeyProvider;
use App\Services\Node\NodePairingState;
use App\Services\Node\NodeSetupService;

/**
 * Deployment and configuration readiness for the God Mode landing screen
 * (GOD-006; technical spec 22.5.2, 25.3, 26.2).
 *
 * Five signals are reported: node configuration completeness, node role and
 * pairing state, presence of required secrets, secure connection policy status,
 * and PowerSync connectivity.
 *
 * The last two are not evaluated here. They are the event-mode fail-closed
 * checks in {@see EventModeGuard}, and this reports what that guard already
 * decided rather than applying a second policy that could disagree with the one
 * that actually blocks a node from starting (technical spec 26.2).
 *
 * Nothing in this class writes. Reading the landing screen must not repair the
 * deployment it is describing (GOD-010): a node without keys stays without keys
 * until an operator says otherwise on the node configuration screen.
 */
final class ConfigurationReadinessCheck
{
    public const NO_NODE = 'configuration.no_node';

    public const NODE_NAME_MISSING = 'configuration.node_name_missing';

    public const NODE_ROLE_MISSING = 'configuration.node_role_missing';

    public const PAIRING_INCOMPLETE = 'configuration.pairing_incomplete';

    public const NODE_KEYS_MISSING = 'configuration.node_keys_missing';

    public const APP_KEY_MISSING = 'configuration.app_key_missing';

    public const SECURE_CONNECTION = 'configuration.secure_connection';

    public const POWERSYNC = 'configuration.powersync';

    public const NOTIFICATIONS_SUPPRESSED = 'configuration.notifications_suppressed';

    public const ORGANIZATION_NOTIFICATIONS_SUPPRESSED = 'configuration.organization_notifications_suppressed';

    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly NodeConfigResolver $configResolver,
        private readonly NodePairingState $pairingState,
        private readonly NodeKeyProvider $keys,
        private readonly EventModeGuard $eventMode,
    ) {}

    public function group(): AttentionGroup
    {
        return new AttentionGroup(
            key: AttentionGroup::CONFIGURATION,
            label: 'Deployment and configuration readiness',
            description: 'Whether this node is configured, identified, paired where it needs to be, and able to serve an event securely.',
            items: $this->items(),
        );
    }

    /**
     * @return list<AttentionItem>
     */
    public function items(): array
    {
        $node = $this->nodes->activeNode();

        if (! $node instanceof Node) {
            // Everything below reads from the node, so a missing node is
            // reported once rather than as six derived failures.
            return [
                new AttentionItem(
                    key: self::NO_NODE,
                    label: 'This install has no configured node',
                    detail: 'No active local node exists. Meridian cannot sign operations, pair with central, or serve an event until first-run node setup completes.',
                    resolveRoute: 'platform.node.config',
                    resolveLabel: 'Node Configuration',
                ),
            ];
        }

        return [
            ...$this->nodeConfigurationItems($node),
            ...$this->pairingItems($node),
            ...$this->secretItems($node),
            ...$this->eventModeItems(),
            ...$this->notificationSuppressionItems(),
        ];
    }

    /**
     * Notification sending that is switched off (NOTIFY-009).
     *
     * The requirement asks for suppression to be visible here, and this is the
     * one item in the group that is not a fault. That is deliberate: a
     * deployment that will not mail anybody is a state an operator has to know
     * about before they conclude that notifications are broken, and it is
     * exactly the state a restored production backup is in the moment it boots.
     * A switched-off deployment is far more often correct than not, so the item
     * states the fact and names the setting rather than asking for a fix.
     *
     * @return list<AttentionItem>
     */
    private function notificationSuppressionItems(): array
    {
        $items = [];

        if ((bool) config('meridian.notifications.suppressed', false)) {
            $items[] = new AttentionItem(
                key: self::NOTIFICATIONS_SUPPRESSED,
                label: 'Notification email is suppressed on this deployment',
                detail: 'MERIDIAN_NOTIFICATIONS_SUPPRESSED is on. Notifications are still recorded with their recipient, type, and subject record, and none are handed to a mail transport. This is the expected state on a development, staging, or restored deployment.',
                resolveRoute: 'platform.system.configuration',
                resolveLabel: 'System Configuration',
            );
        }

        $suppressedOrganizations = Organization::query()
            ->whereNotNull('notifications_suppressed_at')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        foreach ($suppressedOrganizations as $organization) {
            $items[] = new AttentionItem(
                key: self::ORGANIZATION_NOTIFICATIONS_SUPPRESSED.'.'.$organization->getKey(),
                label: sprintf('%s is not sending notification email', $organization->name),
                detail: sprintf(
                    'Notification sending has been switched off for this organization since %s. Its staff receive no application decisions, membership additions, or shift cancellations by email until it is switched back on.',
                    $organization->notifications_suppressed_at?->toDayDateTimeString() ?? 'an unrecorded time',
                ),
                resolveRoute: 'platform.organizations.edit',
                resolveRouteParameters: ['organization' => $organization->getKey()],
                resolveLabel: 'Organization',
            );
        }

        return $items;
    }

    /**
     * @return list<AttentionItem>
     */
    private function nodeConfigurationItems(Node $node): array
    {
        $items = [];
        $values = $this->effectiveValues($node);

        if ($this->isBlank($values['node_name'] ?? null)) {
            $items[] = new AttentionItem(
                key: self::NODE_NAME_MISSING,
                label: 'This node has no name',
                detail: 'Node name is unset. Sync exchanges, pairing, and the Electron health panel all identify this node by name.',
                resolveRoute: 'platform.node.config',
                resolveLabel: 'Node Configuration',
            );
        }

        if ($this->isBlank($values['node_role'] ?? null)) {
            $items[] = new AttentionItem(
                key: self::NODE_ROLE_MISSING,
                label: 'This node has no role',
                detail: 'Node role is unset. Role decides event authority, whether this node pairs with central, and whether event-mode safeguards apply.',
                resolveRoute: 'platform.node.config',
                resolveLabel: 'Node Configuration',
            );
        }

        return $items;
    }

    /**
     * @return list<AttentionItem>
     */
    private function pairingItems(Node $node): array
    {
        $pairing = $this->pairingState->describe($node);

        // A central or development node has no central node to pair with, so
        // "not applicable" is a settled state and not a gap.
        $needsAttention = in_array($pairing['status'], [
            NodePairingState::STATUS_UNPAIRED,
            NodePairingState::STATUS_RECHECK_REQUIRED,
        ], true);

        if (! $needsAttention) {
            return [];
        }

        $detail = $pairing['status'] === NodePairingState::STATUS_RECHECK_REQUIRED
            ? sprintf(
                'This %s node was paired against %s but is now configured for %s. Pairing must be repeated before operations exchange.',
                $node->node_role,
                $pairing['paired_url'] ?? 'a different central node URL',
                $pairing['central_node_url'] ?? 'an unset central node URL',
            )
            : sprintf(
                'This %s node is not paired with a central node, so operations recorded here will not reach central.',
                $node->node_role,
            );

        return [
            new AttentionItem(
                key: self::PAIRING_INCOMPLETE,
                label: 'Central pairing is incomplete: '.$pairing['status_label'],
                detail: $detail,
                resolveRoute: 'platform.node.config',
                resolveLabel: 'Node Configuration',
            ),
        ];
    }

    /**
     * Required secrets: the node signing keypair and the application key
     * (technical spec 26.2). Values are only tested for presence — no secret is
     * read into an attention item, and none is displayed (GOD-026 applies the
     * same rule to the changelog credential).
     *
     * @return list<AttentionItem>
     */
    private function secretItems(Node $node): array
    {
        $items = [];

        if (! $this->keys->hasPrivateKey($node) || $this->keys->publicKeyFor($node) === null) {
            $items[] = new AttentionItem(
                key: self::NODE_KEYS_MISSING,
                label: 'Node signing keys are missing',
                detail: 'This node cannot sign or verify node operations without a keypair, so node-to-node sync fails closed.',
                resolveRoute: 'platform.node.config',
                resolveLabel: 'Node Configuration',
            );
        }

        if ($this->isBlank(config('app.key'))) {
            $items[] = new AttentionItem(
                key: self::APP_KEY_MISSING,
                label: 'The application key is not set',
                detail: 'APP_KEY is unset. Sessions, signed URLs, and encrypted values are not safe until a key is generated.',
                resolveRoute: 'platform.node.config',
                resolveLabel: 'Node Configuration',
            );
        }

        return $items;
    }

    /**
     * Secure connection policy and PowerSync connectivity, as the event-mode
     * guard evaluates them (technical spec 26.2). In development mode the guard
     * runs no checks and this group stays quiet, which is correct: a laptop on
     * plain HTTP is not a deployment fault.
     *
     * @return list<AttentionItem>
     */
    private function eventModeItems(): array
    {
        $items = [];

        foreach ($this->eventMode->evaluate()->failures() as $failure) {
            $items[] = match ($failure->key) {
                EventModeCheck::HTTPS => new AttentionItem(
                    key: self::SECURE_CONNECTION,
                    label: 'Secure connection policy is failing',
                    detail: (string) $failure->reason,
                    resolveRoute: 'platform.node.config',
                    resolveLabel: 'Node Configuration',
                ),
                EventModeCheck::POWERSYNC => new AttentionItem(
                    key: self::POWERSYNC,
                    label: 'PowerSync is not reachable',
                    detail: (string) $failure->reason,
                    resolveRoute: 'platform.node.config',
                    resolveLabel: 'Node Configuration',
                ),
                default => new AttentionItem(
                    key: 'configuration.'.$failure->key,
                    label: $failure->label.' is failing',
                    detail: (string) $failure->reason,
                    resolveRoute: 'platform.node.config',
                    resolveLabel: 'Node Configuration',
                ),
            };
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveValues(Node $node): array
    {
        $values = [];

        foreach ($this->configResolver->valuesFor($node) as $value) {
            $values[$value['key']] = $value['value'];
        }

        return $values;
    }

    private function isBlank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }
}
