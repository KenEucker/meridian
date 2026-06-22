<?php

namespace App\Services\Credential;

class CredentialEvaluation
{
    public function __construct(
        public readonly bool $eligible,
        public readonly ?string $blockReason = null,
    ) {}

    public static function eligible(): self
    {
        return new self(eligible: true);
    }

    public static function blocked(string $reason): self
    {
        return new self(eligible: false, blockReason: $reason);
    }
}
