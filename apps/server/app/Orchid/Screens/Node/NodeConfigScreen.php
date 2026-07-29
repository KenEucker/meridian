<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Node;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Orchid\Layouts\Node\NodePairingLayout;
use App\Orchid\Layouts\Node\NodeSettingsLayout;
use App\Services\EventMode\EventModeGuard;
use App\Services\EventMode\EventModeNotReadyException;
use App\Services\Node\NodeAlreadyConfiguredException;
use App\Services\Node\NodeConfigResolver;
use App\Services\Node\NodePairingClient;
use App\Services\Node\NodePairingException;
use App\Services\Node\NodePairingState;
use App\Services\Node\NodePairingTokenService;
use App\Services\Node\NodeSetupService;
use App\Services\Node\NodeSyncHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class NodeConfigScreen extends Screen
{
    /**
     * @var Node|null
     */
    public $node;

    /**
     * @var bool
     */
    public $hasUnusedPairingTokens = false;

    /**
     * @return array<string, mixed>
     */
    public function query(
        NodeConfigResolver $resolver,
        NodeSetupService $nodes,
        NodePairingState $pairingState,
        NodePairingTokenService $pairingTokens,
        NodeSyncHealth $syncHealth,
    ): iterable {
        $node = $nodes->activeNode()?->load('configValues');
        $activeTokens = $pairingTokens->activeTokens();

        return [
            'node' => $node,
            'configValues' => $resolver->valuesFor($node),
            'pairing' => $pairingState->describe($node),
            'pairingTokens' => $activeTokens,
            'hasUnusedPairingTokens' => $activeTokens->isNotEmpty(),
            'issuedPairingToken' => session('meridian.issued_pairing_token'),
            // Node sync state is derived from the operation log rather than
            // stored, so this reads the same rows the sync loop writes
            // (technical spec 10.1, 25.3).
            'sync' => $syncHealth->describe($node),
        ];
    }

    public function name(): ?string
    {
        return 'Node Configuration';
    }

    public function description(): ?string
    {
        return 'Server/node settings and effective configuration sources.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.node.config',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            // An install with no node yet is configured from here as well as
            // from the first-run setup page. The setup page exists for the
            // moment before anyone can sign in; once someone can, the console
            // is where node identity is administered, and sending an operator
            // back out to a separate page to do it was the odd part.
            Button::make(__('Set up node'))
                ->icon('bs.check-circle')
                ->method('setUp')
                ->canSee(! $this->node instanceof Node),

            Button::make(__('Save settings'))
                ->icon('bs.check-circle')
                ->method('save')
                ->canSee($this->node instanceof Node),

            Button::make(__('Create pairing token'))
                ->icon('bs.key')
                ->method('createPairingToken')
                ->canSee($this->node instanceof Node && $this->node->isCentral()),

            Button::make(__('Revoke unused tokens'))
                ->icon('bs.x-circle')
                ->method('revokePairingTokens')
                ->confirm(__('This cannot be undone. Any pairing token that has not been used yet will stop working, and nodes still waiting to pair will need a new one.'))
                ->canSee($this->node instanceof Node && $this->node->isCentral() && $this->hasUnusedPairingTokens),

            Button::make(__('Pair with central'))
                ->icon('bs.link-45deg')
                ->method('pairWithCentral')
                ->canSee($this->node instanceof Node && $this->node->canPairWithCentral()),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        $layouts = [
            Layout::block(NodeSettingsLayout::class)
                ->title($this->node instanceof Node ? __('Node settings') : __('Set up this node'))
                ->description($this->node instanceof Node
                    ? __('These fields configure this Meridian server/node. Client devices discover their settings from the server/API URL and trusted-device flow.')
                    : __('This install has no node identity yet. Naming it here creates the node and its signing keypair, the same as the first-run setup page does.')),
        ];

        if ($this->node instanceof Node && $this->node->canPairWithCentral()) {
            $layouts[] = Layout::block(NodePairingLayout::class)
                ->title(__('Central pairing'))
                ->description(__('Pair this node with its central node using a one-time token created on central.'));
        }

        return [
            ...$layouts,
            Layout::view('orchid.node-config'),
        ];
    }

    /**
     * Create this install's node from the console (technical spec 7.1, 22.2).
     *
     * Runs the same validation, the same event-mode fail-closed check, and the
     * same setup service the first-run setup page uses, so a node created here
     * is indistinguishable from one created there — including its generated
     * signing keypair (technical spec 8.6, 26.2).
     */
    public function setUp(Request $request, EventModeGuard $eventMode, NodeSetupService $nodes): RedirectResponse
    {
        $settings = $this->validatedSettings($request);

        try {
            $eventMode->ensureReady($settings['node_role']);
        } catch (EventModeNotReadyException $exception) {
            return redirect()
                ->route('platform.node.config')
                ->withErrors(['node.node_role' => $exception->getMessage()])
                ->withInput();
        }

        try {
            $node = $nodes->setupFirstNode(
                nodeName: $settings['node_name'],
                nodeRole: $settings['node_role'],
                centralNodeUrl: $settings['central_node_url'],
                updatedBy: $request->user(),
            );
        } catch (NodeAlreadyConfiguredException $exception) {
            return redirect()
                ->route('platform.node.config')
                ->withErrors(['node.node_name' => $exception->getMessage()]);
        }

        Toast::info(__('This node is set up as :name.', ['name' => $node->node_name]));

        return redirect()->route('platform.node.config');
    }

    public function save(Request $request, EventModeGuard $eventMode, NodeSetupService $nodes): RedirectResponse
    {
        $node = $nodes->activeNode()?->load('configValues');

        if (! $node instanceof Node) {
            Toast::warning(__('No active node is available to update.'));

            return redirect()->route('platform.node.config');
        }

        $settings = $this->validatedSettings($request);
        $centralNodeUrl = $settings['central_node_url'];

        try {
            $eventMode->ensureReady($settings['node_role']);
        } catch (EventModeNotReadyException $exception) {
            return redirect()
                ->route('platform.node.config')
                ->withErrors(['node.node_role' => $exception->getMessage()])
                ->withInput();
        }

        $node->forceFill([
            'node_name' => $settings['node_name'],
            'node_role' => $settings['node_role'],
            'central_node_url' => $centralNodeUrl,
        ])->save();

        $this->storeDatabaseOverride(
            $node,
            'node_name',
            $settings['node_name'],
            $request,
        );
        $this->storeDatabaseOverride(
            $node,
            'node_role',
            $settings['node_role'],
            $request,
        );
        $this->storeDatabaseOverride(
            $node,
            'central_node_url',
            $centralNodeUrl,
            $request,
        );

        Toast::info(__('Node settings were saved.'));

        return redirect()->route('platform.node.config');
    }

    /**
     * Create a one-time pairing token on this central node (technical spec
     * 7.3). The plaintext token is flashed for a single render and is never
     * recoverable afterwards.
     */
    public function createPairingToken(Request $request, NodePairingTokenService $tokens): RedirectResponse
    {
        try {
            $issued = $tokens->issue(issuedBy: $request->user());
        } catch (NodePairingException $exception) {
            return redirect()
                ->route('platform.node.config')
                ->withErrors(['pairing' => $exception->getMessage()]);
        }

        Toast::info(__('A one-time pairing token was created. Copy it now; it is not shown again.'));

        return redirect()
            ->route('platform.node.config')
            ->with('meridian.issued_pairing_token', $issued->plaintext);
    }

    /**
     * Revoke every pairing token this central node has issued that has not been
     * used yet. Alpha 1 tokens do not expire (technical spec 7.3), so revoking
     * is the only way to retire a token that was issued in error or lost.
     */
    public function revokePairingTokens(Request $request, NodePairingTokenService $tokens): RedirectResponse
    {
        $revoked = 0;

        foreach ($tokens->activeTokens() as $token) {
            $tokens->revoke($token, revokedBy: $request->user());
            $revoked++;
        }

        Toast::info(trans_choice(
            '{0}There were no unused pairing tokens to revoke.|{1}One unused pairing token was revoked.|[2,*]:count unused pairing tokens were revoked.',
            $revoked,
            ['count' => $revoked],
        ));

        return redirect()->route('platform.node.config');
    }

    /**
     * Pair this on-site or standalone node with its central node using a
     * one-time token created on central (technical spec 7.3, 7.4).
     */
    public function pairWithCentral(Request $request, NodePairingClient $pairing): RedirectResponse
    {
        $validated = $request->validate([
            'pairing.token' => ['required', 'string', 'max:255'],
            'pairing.central_node_url' => ['nullable', 'url', 'max:2048'],
        ]);

        try {
            $result = $pairing->pair(
                plaintextToken: $validated['pairing']['token'],
                centralNodeUrl: $validated['pairing']['central_node_url'] ?? null,
                actor: $request->user(),
            );
        } catch (NodePairingException $exception) {
            return redirect()
                ->route('platform.node.config')
                ->withErrors(['pairing.token' => $exception->getMessage()]);
        }

        Toast::info(__('This node is paired with :central.', [
            'central' => $result['central_node']->node_name,
        ]));

        return redirect()->route('platform.node.config');
    }

    /**
     * The node settings form, validated the same way whether it is creating
     * this install's node or editing it.
     *
     * @return array{node_name: string, node_role: string, central_node_url: string|null}
     */
    private function validatedSettings(Request $request): array
    {
        $validated = $request->validate([
            'node.node_name' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i',
            ],
            'node.node_role' => ['required', Rule::in(Node::ROLES)],
            'node.central_node_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $centralNodeUrl = $validated['node']['central_node_url'] ?? null;

        return [
            'node_name' => $validated['node']['node_name'],
            'node_role' => $validated['node']['node_role'],
            'central_node_url' => $centralNodeUrl === '' ? null : $centralNodeUrl,
        ];
    }

    private function storeDatabaseOverride(
        Node $node,
        string $key,
        mixed $value,
        Request $request,
    ): NodeConfigValue {
        return $node->configValues()->updateOrCreate(
            ['key' => $key],
            [
                'value_json' => $value,
                'source' => NodeConfigValue::SOURCE_DATABASE,
                'updated_by_user_id' => $request->user()?->id,
            ],
        );
    }
}
