<?php

namespace App\Services\Node;

use App\Models\Node;
use App\Models\NodeConfigValue;
use Illuminate\Support\Collection;

class NodeConfigResolver
{
    /**
     * @var array<string, array{label: string, config: string, default: mixed, sensitive?: bool}>
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
            ->map(fn (array $definition, string $key): array => $this->resolveValue($key, $definition, $databaseValues))
            ->values()
            ->all();
    }

    /**
     * @param  array{label: string, config: string, default: mixed, sensitive?: bool}  $definition
     * @param  Collection<string, NodeConfigValue>  $databaseValues
     * @return array{key: string, label: string, source: string, source_label: string, value: mixed, display_value: string, sensitive: bool}
     */
    private function resolveValue(string $key, array $definition, Collection $databaseValues): array
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

        $fileValue = config($definition['config']);

        if ($fileValue !== null && $fileValue !== '') {
            return $this->resolved(
                key: $key,
                label: $definition['label'],
                source: NodeConfigValue::SOURCE_FILE,
                value: $fileValue,
                sensitive: $sensitive,
            );
        }

        return $this->resolved(
            key: $key,
            label: $definition['label'],
            source: NodeConfigValue::SOURCE_RUNTIME,
            value: $definition['default'],
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
