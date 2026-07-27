<?php

namespace App\Services\Node;

/**
 * Generates and hashes central node pairing tokens (technical spec 7.3).
 *
 * The plaintext token is the pairing credential and is shown once at issue
 * time. Only the SHA-256 hash is persisted, so a database read cannot recover
 * a usable token.
 */
class NodePairingTokenGenerator
{
    public const PREFIX = 'mrdn-pair-';

    private const RANDOM_BYTES = 24;

    public static function generate(): string
    {
        return self::PREFIX.bin2hex(random_bytes(self::RANDOM_BYTES));
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', self::normalize($plaintext));
    }

    /**
     * Operators copy tokens between two machines, so surrounding whitespace and
     * letter case are not meaningful.
     */
    public static function normalize(string $plaintext): string
    {
        return strtolower(trim($plaintext));
    }
}
