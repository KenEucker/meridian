<?php

namespace App\Services\Node;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Reads and writes the pairing state an on-site or standalone node keeps about
 * its central node (technical spec 7.3).
 *
 * Pairing state lives in node config values so God mode shows it with the same
 * file/database/runtime source labels as the rest of node config. The status is
 * derived rather than stored, which is how "changing the central node URL
 * triggers a node-pairing recheck" is enforced: a pairing recorded against a
 * different URL no longer reads as paired.
 */
class NodePairingState
{
    public const CONFIG_CENTRAL_NODE_URL = 'central_node_url';

    public const CONFIG_CENTRAL_NODE_NAME = 'central_node_name';

    public const CONFIG_CENTRAL_NODE_PUBLIC_KEY = 'central_node_public_key';

    public const CONFIG_CENTRAL_PAIRED_URL = 'central_node_paired_url';

    public const CONFIG_CENTRAL_PAIRED_AT = 'central_node_paired_at';

    /** No node is configured on this install yet. */
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    /** This node role does not pair with a central node. */
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /** This node can pair but has not completed pairing. */
    public const STATUS_UNPAIRED = 'unpaired';

    /** A pairing exists, but the configured central URL has changed since. */
    public const STATUS_RECHECK_REQUIRED = 'recheck_required';

    public const STATUS_PAIRED = 'paired';

    public function __construct(private readonly NodeConfigResolver $configResolver) {}

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     central_node_url: ?string,
     *     central_node_name: ?string,
     *     paired_url: ?string,
     *     paired_at: ?string,
     * }
     */
    public function describe(?Node $node): array
    {
        $values = $this->effectiveValues($node);

        $centralUrl = $this->stringValue($values, self::CONFIG_CENTRAL_NODE_URL);
        $pairedUrl = $this->stringValue($values, self::CONFIG_CENTRAL_PAIRED_URL);
        $pairedAt = $this->stringValue($values, self::CONFIG_CENTRAL_PAIRED_AT);

        return [
            'status' => $status = $this->resolveStatus($node, $centralUrl, $pairedUrl, $pairedAt),
            'status_label' => self::statusLabel($status),
            'central_node_url' => $centralUrl,
            'central_node_name' => $this->stringValue($values, self::CONFIG_CENTRAL_NODE_NAME),
            'paired_url' => $pairedUrl,
            'paired_at' => $pairedAt,
        ];
    }

    public function status(?Node $node): string
    {
        return $this->describe($node)['status'];
    }

    /**
     * The central node URL this node should pair against, file-first and
     * database-second like the rest of node config.
     */
    public function configuredCentralUrl(?Node $node): ?string
    {
        return $this->stringValue($this->effectiveValues($node), self::CONFIG_CENTRAL_NODE_URL);
    }

    /**
     * Record a completed pairing against the URL that was actually used.
     */
    public function recordPairing(
        Node $node,
        string $centralNodeUrl,
        Node $centralNode,
        Carbon $pairedAt,
        ?User $updatedBy = null,
    ): void {
        $this->storeOverride($node, self::CONFIG_CENTRAL_NODE_URL, $centralNodeUrl, $updatedBy);
        $this->storeOverride($node, self::CONFIG_CENTRAL_NODE_NAME, $centralNode->node_name, $updatedBy);
        $this->storeOverride($node, self::CONFIG_CENTRAL_NODE_PUBLIC_KEY, $centralNode->public_key, $updatedBy);
        $this->storeOverride($node, self::CONFIG_CENTRAL_PAIRED_URL, self::normalizeUrl($centralNodeUrl), $updatedBy);
        $this->storeOverride($node, self::CONFIG_CENTRAL_PAIRED_AT, $pairedAt->toIso8601String(), $updatedBy);
    }

    public function storeOverride(Node $node, string $key, mixed $value, ?User $updatedBy = null): NodeConfigValue
    {
        return $node->configValues()->updateOrCreate(
            ['key' => $key],
            [
                'value_json' => $value,
                'source' => NodeConfigValue::SOURCE_DATABASE,
                'updated_by_user_id' => $updatedBy?->getKey(),
            ],
        );
    }

    public static function normalizeUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_NOT_CONFIGURED => 'No node configured',
            self::STATUS_NOT_APPLICABLE => 'Not applicable for this node role',
            self::STATUS_UNPAIRED => 'Not paired',
            self::STATUS_RECHECK_REQUIRED => 'Pairing recheck required',
            self::STATUS_PAIRED => 'Paired with central',
            default => $status,
        };
    }

    private function resolveStatus(
        ?Node $node,
        ?string $centralUrl,
        ?string $pairedUrl,
        ?string $pairedAt,
    ): string {
        if (! $node instanceof Node) {
            return self::STATUS_NOT_CONFIGURED;
        }

        if (! $node->canPairWithCentral()) {
            return self::STATUS_NOT_APPLICABLE;
        }

        if ($centralUrl === null) {
            return self::STATUS_UNPAIRED;
        }

        if ($pairedAt === null || $pairedUrl === null) {
            return self::STATUS_UNPAIRED;
        }

        // Changing the central node URL triggers a node-pairing recheck
        // (technical spec 7.3).
        if (self::normalizeUrl($centralUrl) !== self::normalizeUrl($pairedUrl)) {
            return self::STATUS_RECHECK_REQUIRED;
        }

        return self::STATUS_PAIRED;
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveValues(?Node $node): array
    {
        $values = [];

        foreach ($this->configResolver->valuesFor($node) as $value) {
            $values[$value['key']] = $value['value'];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function stringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
