<?php

declare(strict_types=1);

namespace App\Services\SystemConfig;

use App\Models\Node;
use App\Models\SystemConfigOverride;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Applies valid, active database overrides to Laravel's runtime configuration
 * repository at boot (technical spec 22A.6; SYS-005, SYS-008 through SYS-010,
 * SYS-022).
 *
 * Application code keeps reading `config()`; nothing anywhere does a
 * per-setting database lookup. This runs once per boot, works on top of a
 * cached configuration (`config:cache` caches the file layer; overrides are
 * applied over it), and fails soft: when the override table cannot be read —
 * missing database, pre-migration boot, corrupted table — the node continues
 * on normal Laravel configuration, logs one sanitized warning, and the
 * failure is surfaced as a critical diagnostics result (SYS-022).
 *
 * Bootstrap-locked, read-only, managed, and unmapped variables are never
 * applied here regardless of what the table claims, and an override that
 * fails typed validation or decryption is skipped and reported rather than
 * loaded (SYS-006, SYS-010).
 */
class ApplySystemConfigOverrides
{
    private bool $applied = false;

    private bool $loadFailed = false;

    private ?string $loadFailureReason = null;

    /**
     * @var list<array{name: string, reason: string}>
     */
    private array $skipped = [];

    /**
     * @var list<string>
     */
    private array $appliedNames = [];

    public function __construct(
        private readonly EnvExampleCatalog $catalog,
        private readonly SystemConfigValueCodec $codec,
    ) {}

    public function apply(): void
    {
        if ($this->applied) {
            return;
        }

        $this->applied = true;

        try {
            // A boot before the migration has run — a fresh install, a test
            // process, a deploy mid-rollout — is a normal state, not a load
            // failure. Only an unreadable database is a failure.
            if (! Schema::hasTable('system_config_overrides')) {
                return;
            }

            $overrides = SystemConfigOverride::query()
                ->active()
                ->whereIn(
                    'node_id',
                    Node::query()->active()->local()->select('id'),
                )
                ->get();
        } catch (Throwable $exception) {
            // The reason is logged by exception class only: connection errors
            // embed DSNs and credentials in their messages, and this log line
            // must stay safe to ship in a diagnostics bundle (SYS-022).
            $this->loadFailed = true;
            $this->loadFailureReason = $exception::class;

            Log::warning('System configuration overrides could not be loaded; continuing on environment configuration.', [
                'exception' => $exception::class,
            ]);

            return;
        }

        foreach ($overrides as $override) {
            $this->applyOverride($override);
        }
    }

    /**
     * Reset per-boot state so tests exercising multiple boots can re-apply.
     */
    public function fresh(): void
    {
        $this->applied = false;
        $this->loadFailed = false;
        $this->loadFailureReason = null;
        $this->skipped = [];
        $this->appliedNames = [];
    }

    public function loadFailed(): bool
    {
        return $this->loadFailed;
    }

    public function loadFailureReason(): ?string
    {
        return $this->loadFailureReason;
    }

    /**
     * @return list<array{name: string, reason: string}>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * @return list<string>
     */
    public function appliedNames(): array
    {
        return $this->appliedNames;
    }

    private function applyOverride(SystemConfigOverride $override): void
    {
        $entry = $this->catalog->entry($override->name);

        if ($entry === null) {
            $this->skip($override->name, 'not in the configuration catalogue');

            return;
        }

        if (! $entry->editable()) {
            $this->skip($override->name, 'not overridable from the database');

            return;
        }

        if ($entry->activation() === CatalogEntry::ACTIVATION_DEPLOY) {
            // Deploy-class values are consumed outside this process; setting
            // them here would claim an activation that has not happened.
            return;
        }

        try {
            $value = $this->decodedValue($entry, $override);
        } catch (Throwable $exception) {
            $this->skip($override->name, $exception instanceof InvalidSystemConfigValue
                ? $exception->getMessage()
                : 'stored value could not be decoded');

            return;
        }

        foreach ($entry->configKeys as $key) {
            Config::set($key, $value);
        }

        $this->appliedNames[] = $override->name;
    }

    private function decodedValue(CatalogEntry $entry, SystemConfigOverride $override): mixed
    {
        if ($override->is_secret) {
            // Decryption failures throw here and are reported as skipped;
            // the plaintext exists only inside the config repository, exactly
            // like a secret read from the environment (SYS-013).
            $value = $override->secret_value;

            if ($value === null) {
                throw InvalidSystemConfigValue::forType($entry->name, $entry->type, 'stored secret is empty');
            }

            return $value;
        }

        if ($override->value_json === null) {
            throw InvalidSystemConfigValue::forType($entry->name, $entry->type, 'stored override has no value');
        }

        $decoded = $this->codec->decode($override->value_json);
        $this->codec->validateTyped($entry, $decoded);

        return $decoded;
    }

    private function skip(string $name, string $reason): void
    {
        $this->skipped[] = ['name' => $name, 'reason' => $reason];

        Log::warning('Skipped an invalid system configuration override.', [
            'name' => $name,
            'reason' => $reason,
        ]);
    }
}
