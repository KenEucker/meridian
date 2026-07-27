<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Node;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Orchid\Layouts\Node\NodePairingLayout;
use App\Orchid\Layouts\Node\NodeSettingsLayout;
use App\Services\EventMode\EventModeGuard;
use App\Services\EventMode\EventModeNotReadyException;
use App\Services\Node\NodeConfigResolver;
use App\Services\Node\NodePairingClient;
use App\Services\Node\NodePairingException;
use App\Services\Node\NodePairingState;
use App\Services\Node\NodePairingTokenService;
use App\Services\Node\NodeSetupService;
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
     * @return array<string, mixed>
     */
    public function query(
        NodeConfigResolver $resolver,
        NodeSetupService $nodes,
        NodePairingState $pairingState,
        NodePairingTokenService $pairingTokens,
    ): iterable {
        $node = $nodes->activeNode()?->load('configValues');

        return [
            'node' => $node,
            'configValues' => $resolver->valuesFor($node),
            'pairing' => $pairingState->describe($node),
            'pairingTokens' => $pairingTokens->activeTokens(),
            'issuedPairingToken' => session('meridian.issued_pairing_token'),
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
            Button::make(__('Save settings'))
                ->icon('bs.check-circle')
                ->method('save')
                ->canSee($this->node instanceof Node),

            Button::make(__('Create pairing token'))
                ->icon('bs.key')
                ->method('createPairingToken')
                ->canSee($this->node instanceof Node && $this->node->isCentral()),

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
        $layouts = [];

        if ($this->node instanceof Node) {
            $layouts[] = Layout::block(NodeSettingsLayout::class)
                ->title(__('Node settings'))
                ->description(__('These fields configure this Meridian server/node. Client devices discover their settings from the server/API URL and trusted-device flow.'));
        }

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

    public function save(Request $request, EventModeGuard $eventMode, NodeSetupService $nodes): RedirectResponse
    {
        $node = $nodes->activeNode()?->load('configValues');

        if (! $node instanceof Node) {
            Toast::warning(__('No active node is available to update.'));

            return redirect()->route('platform.node.config');
        }

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

        $settings = $validated['node'];
        $centralNodeUrl = $settings['central_node_url'] ?? null;
        $centralNodeUrl = $centralNodeUrl === '' ? null : $centralNodeUrl;

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
