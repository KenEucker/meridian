<?php

declare(strict_types=1);

namespace App\Services\SystemConfig;

/**
 * One environment variable known to the System Configuration catalogue
 * (technical spec 22A.2; SYS-001 through SYS-004).
 *
 * Entries are parsed from `.env.example`, which is the single catalogue
 * source: the variable's position gives its section, the comment block above
 * it gives its description, and `@tag` comments give the structured metadata.
 * Nothing here is invented at runtime — an entry without an `@config` mapping
 * is honest about it and stays read-only rather than guessing where the value
 * would land (SYS-004).
 */
final class CatalogEntry
{
    public const TYPE_STRING = 'string';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_FLOAT = 'float';

    public const TYPE_JSON = 'json';

    public const TYPE_URL = 'url';

    public const TYPE_DURATION = 'duration';

    public const TYPE_ENUM = 'enum';

    /** Activation classes (technical spec 22A.4; SYS-008). */
    public const ACTIVATION_BOOTSTRAP = 'bootstrap';

    public const ACTIVATION_REQUEST = 'request';

    public const ACTIVATION_WORKERS = 'workers';

    public const ACTIVATION_DEPLOY = 'deploy';

    /**
     * @param  list<string>  $configKeys
     * @param  list<string>  $enumValues
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $description,
        public readonly string $section,
        public readonly ?string $exampleValue,
        public readonly bool $commentedOut,
        public readonly string $type,
        public readonly array $enumValues,
        public readonly array $configKeys,
        public readonly bool $secret,
        public readonly bool $required,
        public readonly bool $bootstrapLocked,
        public readonly ?string $managedLabel,
        public readonly bool $readonly,
        public readonly ?string $restart,
    ) {}

    /**
     * Whether a database override may be created for this variable. Editing
     * requires a truthful config mapping and an activation class that a
     * running node can honour (SYS-005, SYS-008).
     */
    public function editable(): bool
    {
        return ! $this->bootstrapLocked
            && ! $this->readonly
            && $this->managedLabel === null
            && $this->configKeys !== [];
    }

    public function mapped(): bool
    {
        return $this->configKeys !== [];
    }

    /**
     * The activation class shown to operators: what has to happen before a
     * saved override is fully active (technical spec 22A.4).
     */
    public function activation(): string
    {
        if ($this->bootstrapLocked) {
            return self::ACTIVATION_BOOTSTRAP;
        }

        return match ($this->restart) {
            'workers' => self::ACTIVATION_WORKERS,
            'deploy' => self::ACTIVATION_DEPLOY,
            default => self::ACTIVATION_REQUEST,
        };
    }

    public function activationLabel(): string
    {
        return match ($this->activation()) {
            self::ACTIVATION_BOOTSTRAP => 'Bootstrap-locked: file/environment only',
            self::ACTIVATION_WORKERS => 'New requests immediately; restart queue/scheduler workers',
            self::ACTIVATION_DEPLOY => 'Requires service restart or redeployment',
            default => 'New requests and new processes at next boot',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'section' => $this->section,
            'example_value' => $this->secret ? null : $this->exampleValue,
            'commented_out' => $this->commentedOut,
            'type' => $this->type,
            'enum_values' => $this->enumValues,
            'config_keys' => $this->configKeys,
            'secret' => $this->secret,
            'required' => $this->required,
            'bootstrap_locked' => $this->bootstrapLocked,
            'managed_label' => $this->managedLabel,
            'readonly' => $this->readonly,
            'editable' => $this->editable(),
            'activation' => $this->activation(),
            'activation_label' => $this->activationLabel(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            label: (string) $data['label'],
            description: (string) $data['description'],
            section: (string) $data['section'],
            exampleValue: $data['example_value'] !== null ? (string) $data['example_value'] : null,
            commentedOut: (bool) $data['commented_out'],
            type: (string) $data['type'],
            enumValues: array_values($data['enum_values'] ?? []),
            configKeys: array_values($data['config_keys'] ?? []),
            secret: (bool) $data['secret'],
            required: (bool) $data['required'],
            bootstrapLocked: (bool) $data['bootstrap_locked'],
            managedLabel: $data['managed_label'] !== null ? (string) $data['managed_label'] : null,
            readonly: (bool) $data['readonly'],
            restart: match ($data['activation'] ?? null) {
                self::ACTIVATION_WORKERS => 'workers',
                self::ACTIVATION_DEPLOY => 'deploy',
                default => null,
            },
        );
    }
}
