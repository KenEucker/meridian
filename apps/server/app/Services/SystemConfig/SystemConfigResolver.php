<?php

declare(strict_types=1);

namespace App\Services\SystemConfig;

use App\Models\Node;
use App\Models\SystemConfigOverride;
use Illuminate\Support\Env;
use Throwable;

/**
 * Resolves each catalogued variable to its effective source and display value
 * on this node (technical spec 22A.6; SYS-016 through SYS-018).
 *
 * Precedence is database override, then process environment / `.env`, then
 * the Laravel configuration default — the same order the boot-time applier
 * enforces, so what this reports and what the process runs on cannot
 * disagree. Secrets are masked here, once, so no caller ever holds a secret
 * display value (SYS-013).
 */
class SystemConfigResolver
{
    public const MASK = '••••••••';

    public function __construct(
        private readonly EnvExampleCatalog $catalog,
        private readonly SystemConfigValueCodec $codec,
    ) {}

    /**
     * @return list<ResolvedConfigValue>
     */
    public function valuesFor(?Node $node): array
    {
        $overrides = $node === null
            ? collect()
            : SystemConfigOverride::query()
                ->where('node_id', $node->getKey())
                ->get()
                ->keyBy('name');

        return array_map(
            fn (CatalogEntry $entry): ResolvedConfigValue => $this->resolve($entry, $overrides->get($entry->name)),
            $this->catalog->entries(),
        );
    }

    public function valueFor(?Node $node, string $name): ?ResolvedConfigValue
    {
        $entry = $this->catalog->entry($name);

        if ($entry === null) {
            return null;
        }

        $override = $node === null
            ? null
            : SystemConfigOverride::query()
                ->where('node_id', $node->getKey())
                ->where('name', $name)
                ->first();

        return $this->resolve($entry, $override);
    }

    private function resolve(CatalogEntry $entry, ?SystemConfigOverride $override): ResolvedConfigValue
    {
        $validationError = null;
        $overrideDisplay = null;
        $overridePending = false;

        if ($override !== null) {
            [$overrideDisplay, $validationError, $decoded, $decodable] = $this->describeOverride($entry, $override);

            if ($override->is_active && $validationError === null) {
                return new ResolvedConfigValue(
                    entry: $entry,
                    source: ResolvedConfigValue::SOURCE_DATABASE_OVERRIDE,
                    displayValue: $overrideDisplay,
                    override: $override,
                    overrideDisplayValue: $overrideDisplay,
                    overridePending: $this->isPending($entry, $decoded, $decodable),
                    validationError: null,
                );
            }
        }

        // An invalid or disabled override is reported but never wins; the
        // underlying environment/default value stays effective (SYS-010).
        [$environmentPresent, $environmentValue] = $this->environmentValue($entry->name);

        if ($environmentPresent) {
            return new ResolvedConfigValue(
                entry: $entry,
                source: $validationError !== null
                    ? ResolvedConfigValue::SOURCE_INVALID
                    : ResolvedConfigValue::SOURCE_ENVIRONMENT,
                displayValue: $this->display($entry, $environmentValue),
                override: $override,
                overrideDisplayValue: $overrideDisplay,
                overridePending: false,
                validationError: $validationError,
            );
        }

        if (! $entry->mapped()) {
            return new ResolvedConfigValue(
                entry: $entry,
                source: ResolvedConfigValue::SOURCE_UNMAPPED,
                displayValue: 'Not set',
                override: $override,
                overrideDisplayValue: $overrideDisplay,
                overridePending: false,
                validationError: $validationError,
            );
        }

        $configValue = config($entry->configKeys[0]);

        if ($configValue === null) {
            return new ResolvedConfigValue(
                entry: $entry,
                source: $validationError !== null
                    ? ResolvedConfigValue::SOURCE_INVALID
                    : ResolvedConfigValue::SOURCE_MISSING,
                displayValue: 'Not set',
                override: $override,
                overrideDisplayValue: $overrideDisplay,
                overridePending: false,
                validationError: $validationError,
            );
        }

        return new ResolvedConfigValue(
            entry: $entry,
            source: $validationError !== null
                ? ResolvedConfigValue::SOURCE_INVALID
                : ResolvedConfigValue::SOURCE_LARAVEL_DEFAULT,
            displayValue: $this->display($entry, $configValue),
            override: $override,
            overrideDisplayValue: $overrideDisplay,
            overridePending: false,
            validationError: $validationError,
        );
    }

    /**
     * @return array{0: string, 1: ?string, 2: mixed, 3: bool}
     */
    private function describeOverride(CatalogEntry $entry, SystemConfigOverride $override): array
    {
        if ($override->is_secret) {
            // Secret overrides are describable but never readable: the stored
            // plaintext does not leave the model, and decryption failures are
            // reported as invalid rather than surfaced (SYS-013).
            try {
                $override->secret_value;

                return [self::MASK, null, null, false];
            } catch (Throwable) {
                return [self::MASK, 'The stored secret cannot be decrypted with the current application key.', null, false];
            }
        }

        if ($override->value_json === null) {
            return ['Not set', 'The stored override has no value.', null, false];
        }

        try {
            $decoded = $this->codec->decode($override->value_json);
            $this->codec->validateTyped($entry, $decoded);

            return [$this->display($entry, $decoded), null, $decoded, true];
        } catch (Throwable $exception) {
            return [$this->display($entry, $override->value_json), $exception->getMessage(), null, false];
        }
    }

    /**
     * Whether the stored override differs from what this process is actually
     * running on — a truthful "saved but not yet active" signal (SYS-008).
     */
    private function isPending(CatalogEntry $entry, mixed $decoded, bool $decodable): bool
    {
        if (! $decodable || ! $entry->mapped()) {
            return false;
        }

        if ($entry->activation() === CatalogEntry::ACTIVATION_DEPLOY) {
            return true;
        }

        return config($entry->configKeys[0]) != $decoded;
    }

    /**
     * Whether the variable is present in the process environment / `.env`,
     * and what it holds. `Env::get()` already applies Laravel's literal
     * conversions ("true" to `true`, "empty" to `""`), so the value keeps the
     * type the framework would actually run with.
     *
     * @return array{0: bool, 1: mixed}
     */
    private function environmentValue(string $name): array
    {
        try {
            $present = Env::getRepository()->has($name);
        } catch (Throwable) {
            $present = false;
        }

        return [$present, $present ? Env::get($name) : null];
    }

    private function display(CatalogEntry $entry, mixed $value): string
    {
        if ($entry->secret) {
            return $value === null || $value === '' ? 'Not set' : self::MASK;
        }

        if ($value === null) {
            return 'Not set';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return $value === '' ? '(empty string)' : (string) $value;
        }

        try {
            return $this->codec->encode($value);
        } catch (Throwable) {
            return '(unrepresentable)';
        }
    }
}
