<?php

declare(strict_types=1);

namespace App\Services\SystemConfig;

use JsonException;

/**
 * Typed encode/decode for override values (technical spec 22A.5; SYS-006).
 *
 * Values are stored JSON-encoded so that a missing value, an empty string,
 * `null`, `false`, `0`, and the string `"0"` all remain distinct — flattening
 * everything to strings would silently change Laravel configuration semantics
 * (SYS-007). Decoding is strict: an input that does not satisfy the declared
 * type raises {@see InvalidSystemConfigValue} instead of being coerced into
 * something that merely looks plausible.
 */
class SystemConfigValueCodec
{
    /**
     * Turn operator input (always a string from a form or CLI) into the typed
     * value the catalogue entry declares.
     */
    public function fromInput(CatalogEntry $entry, string $input): mixed
    {
        $type = $entry->type;

        return match ($type) {
            CatalogEntry::TYPE_BOOLEAN => $this->decodeBoolean($entry->name, $input),
            CatalogEntry::TYPE_INTEGER => $this->decodeInteger($entry->name, $input),
            CatalogEntry::TYPE_FLOAT, CatalogEntry::TYPE_DURATION => $this->decodeFloat($entry->name, $input, $type),
            CatalogEntry::TYPE_JSON => $this->decodeJson($entry->name, $input),
            CatalogEntry::TYPE_URL => $this->decodeUrl($entry->name, $input),
            CatalogEntry::TYPE_ENUM => $this->decodeEnum($entry, $input),
            default => $input,
        };
    }

    /**
     * Validate an already-typed value against a catalogue entry, for values
     * that arrive decoded (storage, tests, CLI --json input).
     */
    public function validateTyped(CatalogEntry $entry, mixed $value): void
    {
        $ok = match ($entry->type) {
            CatalogEntry::TYPE_BOOLEAN => is_bool($value),
            CatalogEntry::TYPE_INTEGER => is_int($value),
            CatalogEntry::TYPE_FLOAT, CatalogEntry::TYPE_DURATION => is_int($value) || is_float($value),
            CatalogEntry::TYPE_JSON => true,
            CatalogEntry::TYPE_URL => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            CatalogEntry::TYPE_ENUM => is_string($value) && in_array($value, $entry->enumValues, true),
            default => is_string($value),
        };

        if (! $ok) {
            throw InvalidSystemConfigValue::forType($entry->name, $entry->type, 'stored value has the wrong type');
        }

        if ($entry->type === CatalogEntry::TYPE_DURATION && (float) $value < 0) {
            throw InvalidSystemConfigValue::forType($entry->name, $entry->type, 'durations cannot be negative');
        }
    }

    public function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws JsonException
     */
    public function decode(string $encoded): mixed
    {
        return json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
    }

    private function decodeBoolean(string $name, string $input): bool
    {
        $result = filter_var($input, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($result === null) {
            throw InvalidSystemConfigValue::forType($name, CatalogEntry::TYPE_BOOLEAN, 'use true or false');
        }

        return $result;
    }

    private function decodeInteger(string $name, string $input): int
    {
        if (preg_match('/\A-?\d+\z/', trim($input)) !== 1) {
            throw InvalidSystemConfigValue::forType($name, CatalogEntry::TYPE_INTEGER, 'use a whole number');
        }

        return (int) trim($input);
    }

    private function decodeFloat(string $name, string $input, string $type): float|int
    {
        $trimmed = trim($input);

        if (! is_numeric($trimmed)) {
            throw InvalidSystemConfigValue::forType($name, $type, 'use a number');
        }

        $value = str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;

        if ($type === CatalogEntry::TYPE_DURATION && (float) $value < 0) {
            throw InvalidSystemConfigValue::forType($name, $type, 'durations cannot be negative');
        }

        return $value;
    }

    private function decodeJson(string $name, string $input): mixed
    {
        try {
            return json_decode($input, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidSystemConfigValue::forType($name, CatalogEntry::TYPE_JSON, $exception->getMessage());
        }
    }

    private function decodeUrl(string $name, string $input): string
    {
        if (filter_var($input, FILTER_VALIDATE_URL) === false) {
            throw InvalidSystemConfigValue::forType($name, CatalogEntry::TYPE_URL, 'use an absolute URL');
        }

        return $input;
    }

    private function decodeEnum(CatalogEntry $entry, string $input): string
    {
        if (! in_array($input, $entry->enumValues, true)) {
            throw InvalidSystemConfigValue::forType(
                $entry->name,
                CatalogEntry::TYPE_ENUM,
                'use one of: '.implode(', ', $entry->enumValues),
            );
        }

        return $input;
    }
}
