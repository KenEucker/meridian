<?php

declare(strict_types=1);

namespace App\Services\NodeHealth;

use App\Models\Node;
use App\Services\Node\NodeKeyProvider;
use App\Services\Node\NodePairingState;
use App\Services\Node\NodeSignatureAlgorithm;
use App\Services\Node\NodeSigningException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Signs this node's health report and delivers it to central (technical spec
 * 22A.11; SYS-037).
 *
 * Delivery is best-effort by design: an on-site node with no internet keeps
 * its local report and simply reports again on the next scheduled run. There
 * is no queue and no retry backlog — a health report is only worth sending
 * while it is fresh, which is also why the receiving side enforces the sync
 * replay window rather than accepting an old report late.
 */
class NodeHealthReportClient
{
    public const REPORT_PATH = '/api/node-health-report';

    public function __construct(
        private readonly NodeSignatureAlgorithm $algorithm,
        private readonly NodeKeyProvider $keys,
        private readonly NodePairingState $pairingState,
    ) {}

    /**
     * @throws NodeHealthException
     */
    public function send(Node $localNode, NodeHealthReportPayload $payload): void
    {
        $url = rtrim((string) $this->pairingState->describe($localNode)['central_node_url'], '/');

        if ($url === '') {
            throw NodeHealthException::peerUnreachable('no central node URL is configured.');
        }

        try {
            $privateKey = $this->keys->privateKeyFor($localNode);
        } catch (NodeSigningException $failure) {
            throw NodeHealthException::signingUnavailable($failure->getMessage());
        }

        $signed = $payload->signedWith(
            $this->algorithm->sign($payload->canonicalPayload(), $privateKey),
        );

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout((float) config('meridian.node.sync.request_timeout_seconds', 15))
                ->post($url.self::REPORT_PATH, $signed->toArray());
        } catch (ConnectionException $exception) {
            throw NodeHealthException::peerUnreachable($exception->getMessage());
        }

        if (! $response->successful()) {
            $message = $response->json('message');

            throw NodeHealthException::peerRefused(
                is_string($message) && trim($message) !== '' ? $message : sprintf('HTTP %d.', $response->status()),
            );
        }
    }
}
