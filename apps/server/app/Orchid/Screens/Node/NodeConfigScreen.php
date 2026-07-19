<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Node;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Orchid\Layouts\Node\NodeSettingsLayout;
use App\Services\EventMode\EventModeGuard;
use App\Services\EventMode\EventModeNotReadyException;
use App\Services\Node\NodeConfigResolver;
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
    public function query(NodeConfigResolver $resolver): iterable
    {
        $node = Node::query()
            ->active()
            ->with('configValues')
            ->latest('id')
            ->first();

        return [
            'node' => $node,
            'configValues' => $resolver->valuesFor($node),
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

        return [
            ...$layouts,
            Layout::view('orchid.node-config'),
        ];
    }

    public function save(Request $request, EventModeGuard $eventMode): RedirectResponse
    {
        $node = Node::query()->active()->with('configValues')->latest('id')->first();

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
