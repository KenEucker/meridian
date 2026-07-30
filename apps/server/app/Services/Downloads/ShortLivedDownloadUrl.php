<?php

declare(strict_types=1);

namespace App\Services\Downloads;

use Carbon\CarbonInterface;

/**
 * One issued short-lived download URL and the moment it stops working
 * (CLIENT-019, CLIENT-020; technical spec 11A.6; data/API 5.7).
 *
 * The expiry travels with the URL so a client can tell the difference between a
 * link it let go stale and a link it was never allowed to have.
 */
final readonly class ShortLivedDownloadUrl
{
    public function __construct(
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}

    /**
     * @return array{url: string, expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
