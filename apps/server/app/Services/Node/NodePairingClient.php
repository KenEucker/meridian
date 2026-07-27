<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\EventMode\EventModeGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * On-site side of node pairing (technical spec 7.3, 7.4).
 *
 * The on-site or standalone node presents the one-time token central issued,
 * and stores the central node identity that comes back so later node sync has
 * a verified peer to talk to. Pairing is a node config change and is audited
 * (data/API specification section 8).
 */
class NodePairingClient
{
    public const PAIRING_PATH = '/api/node-pairing';

    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly NodePairingState $state,
        private readonly EventModeGuard $eventMode,
        private readonly AuditService $audit,
    ) {}

    /**
     * Pair this node with central using a one-time pairing token.
     *
     * @return array{central_node: Node, paired_at: \Illuminate\Support\Carbon}
     *
     * @throws NodePairingException
     */
    public function pair(
        string $plaintextToken,
        ?string $centralNodeUrl = null,
        ?User $actor = null,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): array {
        $localNode = $this->nodes->activeNode();

        if (! $localNode instanceof Node) {
            throw NodePairingException::nodeNotConfigured();
        }

        if (! $localNode->canPairWithCentral()) {
            throw NodePairingException::roleCannotPair($localNode->node_role);
        }

        $url = $this->resolveCentralUrl($localNode, $centralNodeUrl);

        $payload = $this->post($url, [
            'pairing_token' => $plaintextToken,
            'node_name' => $localNode->node_name,
            'node_role' => $localNode->node_role,
            'public_key' => $localNode->public_key,
        ]);

        $centralNode = DB::transaction(function () use ($payload, $url, $localNode, $actor): Node {
            $centralNode = $this->storeCentralPeer($payload['central_node'], $url);

            $this->state->recordPairing(
                node: $localNode,
                centralNodeUrl: $url,
                centralNode: $centralNode,
                pairedAt: now(),
                updatedBy: $actor,
            );

            $localNode->forceFill([
                'central_node_url' => $url,
                'paired_at' => now(),
            ])->save();

            return $centralNode;
        });

        $this->audit->recordForEntity(
            entity: $localNode,
            action: 'node.pairing_completed',
            actorUser: $actor,
            actorNode: $localNode,
            after: [
                'central_node_url' => $url,
                'central_node_name' => $centralNode->node_name,
                'paired_at' => $localNode->paired_at?->toIso8601String(),
            ],
            sourceContext: $sourceContext,
        );

        return [
            'central_node' => $centralNode,
            'paired_at' => $localNode->paired_at,
        ];
    }

    /**
     * @throws NodePairingException
     */
    private function resolveCentralUrl(Node $localNode, ?string $centralNodeUrl): string
    {
        $url = $centralNodeUrl !== null && trim($centralNodeUrl) !== ''
            ? trim($centralNodeUrl)
            : $this->state->configuredCentralUrl($localNode);

        if ($url === null || $url === '') {
            throw NodePairingException::centralUrlMissing();
        }

        $url = NodePairingState::normalizeUrl($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'https' && $scheme !== 'http') {
            throw NodePairingException::centralUrlMissing();
        }

        // Production/event mode never uses plain HTTP (technical spec 8.2).
        if ($scheme !== 'https' && $this->eventMode->isEventMode($localNode->node_role)) {
            throw NodePairingException::insecureCentralUrl();
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{central_node: array<string, mixed>, paired_at: ?string}
     *
     * @throws NodePairingException
     */
    private function post(string $url, array $body): array
    {
        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout((float) config('meridian.node.pairing.request_timeout_seconds', 10))
                ->post($url.self::PAIRING_PATH, $body);
        } catch (ConnectionException $exception) {
            throw NodePairingException::centralUnreachable($exception->getMessage());
        }

        if (! $response->successful()) {
            throw NodePairingException::centralRefused($this->refusalDetail($response));
        }

        $payload = $response->json();

        if (! is_array($payload)
            || ! is_array($payload['central_node'] ?? null)
            || ! is_string($payload['central_node']['node_name'] ?? null)
            || ! is_string($payload['central_node']['public_key'] ?? null)) {
            throw NodePairingException::centralRefused('the pairing response was not understood.');
        }

        return [
            'central_node' => $payload['central_node'],
            'paired_at' => is_string($payload['paired_at'] ?? null) ? $payload['paired_at'] : null,
        ];
    }

    private function refusalDetail(Response $response): string
    {
        $message = $response->json('message');

        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        return sprintf('HTTP %d.', $response->status());
    }

    /**
     * @param  array<string, mixed>  $centralNode
     *
     * @throws NodePairingException
     */
    private function storeCentralPeer(array $centralNode, string $url): Node
    {
        $nodeName = (string) $centralNode['node_name'];
        $existing = Node::query()->where('node_name', $nodeName)->first();

        if ($existing instanceof Node && $existing->is_local) {
            throw NodePairingException::nodeNameConflict($nodeName);
        }

        // A revoked peer stays revoked; restoring it is a deliberate God mode
        // act, not a side effect of running pairing again.
        if ($existing instanceof Node && $existing->isRevoked()) {
            throw NodePairingException::nodeRevoked($nodeName);
        }

        $peer = $existing ?? new Node;

        $peer->forceFill([
            'node_name' => $nodeName,
            'node_role' => Node::ROLE_CENTRAL,
            'is_local' => false,
            'public_key' => (string) $centralNode['public_key'],
            'central_node_url' => $url,
            'paired_at' => now(),
        ])->save();

        return $peer;
    }
}
