<?php

namespace App\Services\Node;

use App\Models\Node;
use App\Services\EventMode\EventModeGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The initiating side of node-to-node sync (technical spec 10.1, 10.2).
 *
 * On-site initiates and central answers. That is not a statement about
 * authority — during an active event window on-site is the authoritative node
 * (technical spec 10.2) — but about reachability: central has a routable
 * address and an on-site node on an event network usually does not. Making one
 * exchange carry both directions is what keeps sync bidirectional rather than
 * push-only (technical spec 10.1) without requiring central to open a
 * connection inwards.
 *
 * A run is a loop of exchanges, not a single request. Each exchange carries at
 * most one batch, so a backlog — the queue an on-site node built up while the
 * internet was gone — drains over repeated exchanges within the same run. The
 * loop also carries the previous exchange's acknowledgements and refusals
 * forward, which is what lets the peer stop offering operations this node has
 * already stored or will never accept. Nothing about that bookkeeping is
 * persisted between runs: if a run stops early, the peer simply offers the
 * unacknowledged operations again and this node stores them idempotently
 * (technical spec 10.1), so the loop is self-correcting rather than dependent
 * on state surviving a crash.
 *
 * Failure is expected, not exceptional. When the internet disappears the
 * operations stay `pending` and are pushed on a later run (technical spec
 * 10.2); nothing is marked delivered, and nothing is lost. What is refused is
 * different from what merely failed to arrive: a peer's refusal is recorded
 * against the operation so it stops being offered every minute forever, while
 * an unreachable peer changes no operation state at all.
 */
class NodeSyncClient
{
    public const SYNC_PATH = '/api/node-sync';

    public function __construct(
        private readonly NodeOperationReceiver $receiver,
        private readonly NodeSyncOutbox $outbox,
        private readonly NodeSignatureAlgorithm $algorithm,
        private readonly NodeKeyProvider $keys,
        private readonly NodeSetupService $nodes,
        private readonly NodePairingState $pairing,
        private readonly EventModeGuard $eventMode,
    ) {}

    /**
     * Run one sync with the paired central node.
     *
     * @throws NodeSyncException
     */
    public function sync(?Node $localNode = null): NodeSyncRun
    {
        $localNode = $localNode ?? $this->nodes->activeNode();

        if (! $localNode instanceof Node) {
            throw NodeSyncException::nodeNotConfigured();
        }

        if (! $localNode->canPairWithCentral()) {
            throw NodeSyncException::roleCannotSync((string) $localNode->node_role);
        }

        $peer = $this->pairedCentralNode($localNode);
        $url = $this->peerUrl($localNode);

        $run = new NodeSyncRun($peer->node_name);

        /** @var list<string> $acknowledged */
        $acknowledged = [];
        /** @var list<NodeSyncOperationResult> $refused */
        $refused = [];

        for ($exchange = 0; $exchange < $this->maxExchanges(); $exchange++) {
            $queued = $this->outbox->pendingFor($localNode, $peer);
            $operations = $this->outbox->envelopesFor($queued);

            $request = $this->sign($localNode, NodeSyncRequest::create(
                sourceNode: $localNode,
                operations: $operations,
                acknowledged: $acknowledged,
                refused: $refused,
            ));

            $response = $this->post($url, $request);

            $run = $run->with(exchanges: 1, pushed: count($operations));
            $run = $this->recordPushOutcomes($localNode, $operations, $response, $run);

            [$acknowledged, $refused, $run] = $this->receivePulled($localNode, $response, $run);

            // The loop ends when there is nothing left to move and nothing left
            // to report. A full batch means the queue may hold more, so it
            // keeps going; acknowledgements and refusals produced here are
            // delivered by the next exchange, so a run only ends with
            // outstanding bookkeeping when the exchange cap is reached — and
            // then the peer re-offers, and redelivery is safe.
            $mayHaveMoreToPush = count($operations) >= NodeSyncOutbox::batchSize();

            if (! $mayHaveMoreToPush
                && $response->operations === []
                && $acknowledged === []
                && $refused === []) {
                break;
            }
        }

        return $run;
    }

    /**
     * @param  list<NodeOperationEnvelope>  $operations
     */
    private function recordPushOutcomes(
        Node $localNode,
        array $operations,
        NodeSyncResponse $response,
        NodeSyncRun $run,
    ): NodeSyncRun {
        $accepted = [];
        $refusals = [];

        foreach ($operations as $envelope) {
            $result = $response->resultFor($envelope->uuid());

            // An operation the peer said nothing about is not treated as
            // delivered. It stays pending and is offered again next time.
            if (! $result instanceof NodeSyncOperationResult) {
                continue;
            }

            if ($result->wasAccepted()) {
                $accepted[] = $result->uuid;

                continue;
            }

            $refusals[] = $result;
        }

        $this->outbox->markSent($localNode, $accepted);
        $this->outbox->markRefused($localNode, $refusals);

        return $run->with(accepted: count($accepted), refusedByPeer: count($refusals));
    }

    /**
     * Store and apply the operations the peer handed back, and work out what to
     * report about them on the next exchange.
     *
     * @return array{0: list<string>, 1: list<NodeSyncOperationResult>, 2: NodeSyncRun}
     */
    private function receivePulled(Node $localNode, NodeSyncResponse $response, NodeSyncRun $run): array
    {
        $acknowledged = [];
        $refused = [];
        $stored = 0;
        $applied = 0;
        $unapplied = 0;

        foreach ($response->operations as $envelope) {
            try {
                $receipt = $this->receiver->receiveAndApply($envelope, $localNode);
            } catch (NodeOperationRejectedException $rejection) {
                // Refusing is final: the same operation will be refused for the
                // same reason every time, so the peer is told rather than left
                // to re-offer it forever. The refusal is already audited by the
                // receive path.
                $refused[] = NodeSyncOperationResult::refused($envelope->uuid(), $rejection);

                continue;
            }

            // The operation is held now, whether or not it applied, so it is
            // acknowledged. An operation stored but not applied stays
            // recoverable on this node and is not the peer's problem to resend
            // (technical spec 9.2, 10.3).
            $acknowledged[] = $envelope->uuid();

            $stored += $receipt->stored ? 1 : 0;
            $receipt->wasApplied() ? $applied++ : $unapplied++;
        }

        return [
            $acknowledged,
            $refused,
            $run->with(
                pulled: count($response->operations),
                stored: $stored,
                applied: $applied,
                unapplied: $unapplied,
                refusedHere: count($refused),
            ),
        ];
    }

    /**
     * @throws NodeSyncException
     */
    private function sign(Node $localNode, NodeSyncRequest $request): NodeSyncRequest
    {
        try {
            $privateKey = $this->keys->privateKeyFor($localNode);

            return $request->signedWith(
                $this->algorithm->sign($request->canonicalPayload(), $privateKey),
            );
        } catch (NodeSigningException $failure) {
            throw NodeSyncException::signingUnavailable($failure->getMessage());
        }
    }

    /**
     * @throws NodeSyncException
     */
    private function post(string $url, NodeSyncRequest $request): NodeSyncResponse
    {
        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout((float) config('meridian.node.sync.request_timeout_seconds', 15))
                ->post($url.self::SYNC_PATH, $request->toArray());
        } catch (ConnectionException $exception) {
            throw NodeSyncException::peerUnreachable($exception->getMessage());
        }

        if (! $response->successful()) {
            throw NodeSyncException::peerRefused($this->refusalDetail($response));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw NodeSyncException::peerRefused('the sync response was not understood.');
        }

        return NodeSyncResponse::fromArray($payload);
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
     * The peer this node syncs with: the central node it paired with.
     *
     * Pairing status is checked rather than merely the presence of a peer
     * record, because changing the central node URL puts pairing back into
     * recheck (technical spec 7.3). A node whose central URL has moved should
     * not carry on pushing event data to whatever now answers at the new
     * address until pairing is confirmed again.
     *
     * @throws NodeSyncException
     */
    private function pairedCentralNode(Node $localNode): Node
    {
        $state = $this->pairing->describe($localNode);

        if ($state['status'] !== NodePairingState::STATUS_PAIRED) {
            throw NodeSyncException::notPaired(NodePairingState::statusLabel($state['status']));
        }

        $peer = Node::query()
            ->remote()
            ->active()
            ->where('node_name', $state['central_node_name'])
            ->first();

        if (! $peer instanceof Node) {
            throw NodeSyncException::notPaired(NodePairingState::statusLabel(NodePairingState::STATUS_UNPAIRED));
        }

        return $peer;
    }

    /**
     * @throws NodeSyncException
     */
    private function peerUrl(Node $localNode): string
    {
        $url = $this->pairing->configuredCentralUrl($localNode);

        if ($url === null || trim($url) === '') {
            throw NodeSyncException::centralUrlMissing();
        }

        $url = NodePairingState::normalizeUrl($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'https' && $scheme !== 'http') {
            throw NodeSyncException::centralUrlMissing();
        }

        // Production/event mode never uses plain HTTP (technical spec 8.2).
        if ($scheme !== 'https' && $this->eventMode->isEventMode($localNode->node_role)) {
            throw NodeSyncException::insecureCentralUrl();
        }

        return $url;
    }

    /**
     * How many exchanges one run may make. The cap bounds a single run so a
     * scheduled sync cannot spin indefinitely against a peer with a very large
     * backlog; the remainder drains on the next run.
     */
    private function maxExchanges(): int
    {
        return max(1, (int) config('meridian.node.sync.max_exchanges_per_run', 10));
    }
}
