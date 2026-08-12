<?php

namespace App\Services\Node;

use App\Models\Node;
use App\Models\NodeConfigValue;
use Illuminate\Support\Collection;

class NodeConfigResolver
{
    /**
     * The node config values God Mode reports a source for (technical spec
     * 7.3).
     *
     * `config` is the file-backed key, or null where the value has no file
     * form. `node_attribute` names a column on the node record that supplies
     * the runtime/default tier, for the values the node itself carries — a
     * binding written by setup or pairing is what this node is running with
     * absent anything more explicit. First-run setup writes the record and a
     * database override together (see {@see NodeSetupService}), so a value set
     * that way is reported from the override tier and the record tier is the
     * fallback for a row written some other way.
     *
     * @var array<string, array{label: string, config: ?string, default: mixed, sensitive?: bool, node_attribute?: string}>
     */
    private const KNOWN_VALUES = [
        'node_name' => [
            'label' => 'Node name',
            'config' => 'meridian.node.name',
            'default' => null,
        ],
        'node_role' => [
            'label' => 'Node role',
            'config' => 'meridian.node.role',
            'default' => Node::ROLE_DEVELOPMENT,
        ],
        'node_public_key' => [
            'label' => 'Node public key',
            'config' => 'meridian.node.public_key',
            'default' => null,
        ],
        'node_private_key' => [
            'label' => 'Node private key',
            'config' => 'meridian.node.private_key',
            'default' => null,
            'sensitive' => true,
        ],
        'central_node_url' => [
            'label' => 'Central node URL',
            'config' => 'meridian.node.central_node_url',
            'default' => null,
        ],
        // The organization and event this node is bound to (technical spec
        // 7.3). Both are read by product code — the organization narrows God
        // Mode navigation to the modules that organization runs (M19.14,
        // MOD-021), and a node naming an event does not serve the marketing
        // surface (PUBLIC-006) — so both have to report where they came from,
        // which is what technical spec 7.3 asks of every config value.
        'organization_id' => [
            'label' => 'Organization binding',
            'config' => 'meridian.node.organization_id',
            'default' => null,
            'node_attribute' => 'organization_id',
        ],
        // No file form, deliberately. The event binding is written by the node
        // itself, and an environment variable that could point a prepared image
        // at an event would decide product behavior — the marketing surface
        // refuses on it — from a value nobody on the node had set. A task that
        // needs file-configured event binding adds the key then.
        'event_id' => [
            'label' => 'Event binding',
            'config' => null,
            'default' => null,
            'node_attribute' => 'event_id',
        ],
        // Pairing state recorded when this node paired with central
        // (technical spec 7.3).
        'central_node_name' => [
            'label' => 'Central node name',
            'config' => 'meridian.node.central_node_name',
            'default' => null,
        ],
        'central_node_public_key' => [
            'label' => 'Central node public key',
            'config' => 'meridian.node.central_node_public_key',
            'default' => null,
        ],
        'central_node_paired_url' => [
            'label' => 'Central node URL at pairing',
            'config' => 'meridian.node.central_node_paired_url',
            'default' => null,
        ],
        'central_node_paired_at' => [
            'label' => 'Central node paired at',
            'config' => 'meridian.node.central_node_paired_at',
            'default' => null,
        ],
    ];

    /**
     * @return list<array{key: string, label: string, source: string, source_label: string, value: mixed, display_value: string, sensitive: bool}>
     */
    public function valuesFor(?Node $node): array
    {
        $databaseValues = $node?->configValues()
            ->get()
            ->keyBy('key') ?? new Collection;

        return collect(self::KNOWN_VALUES)
            ->map(fn (array $definition, string $key): array => $this->resolveValue($key, $definition, $databaseValues, $node))
            ->values()
            ->all();
    }

    /**
     * One config value, resolved through the same precedence the console
     * displays it with.
     *
     * A caller acting on a node config value reads it here rather than reaching
     * for `config()` or the node record directly, so what the product does and
     * what God Mode says it is doing cannot drift apart (technical spec 7.3).
     */
    public function value(string $key, ?Node $node): mixed
    {
        $definition = self::KNOWN_VALUES[$key] ?? null;

        if ($definition === null) {
            return null;
        }

        $databaseValues = $node?->configValues()
            ->get()
            ->keyBy('key') ?? new Collection;

        return $this->resolveValue($key, $definition, $databaseValues, $node)['value'];
    }

    /**
     * @param  array{label: string, config: ?string, default: mixed, sensitive?: bool, node_attribute?: string}  $definition
     * @param  Collection<string, NodeConfigValue>  $databaseValues
     * @return array{key: string, label: string, source: string, source_label: string, value: mixed, display_value: string, sensitive: bool}
     */
    private function resolveValue(string $key, array $definition, Collection $databaseValues, ?Node $node = null): array
    {
        $sensitive = $definition['sensitive'] ?? false;
        $override = $databaseValues->get($key);

        if ($override instanceof NodeConfigValue) {
            return $this->resolved(
                key: $key,
                label: $definition['label'],
                source: $override->source,
                value: $override->value_json,
                sensitive: $sensitive,
            );
        }

        $fileValue = $definition['config'] === null ? null : config($definition['config']);

        if ($fileValue !== null && $fileValue !== '') {
            return $this->resolved(
                key: $key,
                label: $definition['label'],
                source: NodeConfigValue::SOURCE_FILE,
                value: $fileValue,
                sensitive: $sensitive,
            );
        }

        // What the node itself is running with, where the value is one the node
        // record carries. Reported as runtime rather than as a database
        // override, because it is the node's own state and not something an
        // operator set here.
        $attribute = $definition['node_attribute'] ?? null;

        $recordValue = $attribute === null || ! $node instanceof Node
            ? null
            : $node->getAttribute($attribute);

        return $this->resolved(
            key: $key,
            label: $definition['label'],
            source: NodeConfigValue::SOURCE_RUNTIME,
            value: $recordValue ?? $definition['default'],
            sensitive: $sensitive,
        );
    }

    /**
     * @return array{key: string, label: string, source: string, source_label: string, value: mixed, display_value: string, sensitive: bool}
     */
    private function resolved(string $key, string $label, string $source, mixed $value, bool $sensitive): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'source' => $source,
            'source_label' => NodeConfigValue::sourceLabel($source),
            'value' => $value,
            'display_value' => $this->displayValue($value, $sensitive),
            'sensitive' => $sensitive,
        ];
    }

    private function displayValue(mixed $value, bool $sensitive): string
    {
        if ($sensitive) {
            return $value === null || $value === '' ? 'Not set' : 'Configured (hidden)';
        }

        if ($value === null || $value === '') {
            return 'Not set';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
