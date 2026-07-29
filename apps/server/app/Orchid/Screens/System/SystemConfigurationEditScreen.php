<?php

declare(strict_types=1);

namespace App\Orchid\Screens\System;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\SystemConfigOverride;
use App\Services\Node\NodeSetupService;
use App\Services\SystemConfig\InvalidSystemConfigValue;
use App\Services\SystemConfig\ResolvedConfigValue;
use App\Services\SystemConfig\SystemConfigOverrideStore;
use App\Services\SystemConfig\SystemConfigResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * Edit one catalogued variable's node-local database override (technical spec
 * 22A.7; SYS-005 through SYS-010, SYS-014, SYS-015).
 *
 * Bootstrap-locked, managed, and unmapped variables render read-only with the
 * reason stated. Secrets accept a replacement value and never render the
 * stored one — a privileged user may replace a secret but not retrieve it
 * (SYS-014). Every change requires the manage permission; secret changes
 * additionally require the secrets permission and a change reason.
 */
class SystemConfigurationEditScreen extends Screen
{
    /**
     * @var ResolvedConfigValue|null
     */
    public $value;

    /**
     * @var Node|null
     */
    public $node;

    public string $variable = '';

    /**
     * @return array<string, mixed>
     */
    public function query(
        string $variable,
        Request $request,
        NodeSetupService $nodes,
        SystemConfigResolver $resolver,
    ): iterable {
        $node = $nodes->activeNode();
        $value = $resolver->valueFor($node, $variable);

        if ($value === null) {
            abort(404, 'This variable is not in the configuration catalogue.');
        }

        return [
            'variable' => $variable,
            'node' => $node,
            'value' => $value,
            'auditEvents' => $this->auditEvents($request, $value),
            'canManage' => $this->canManage($request),
            'canManageSecret' => $this->canManageSecret($request),
            'canSeeAudit' => $request->user()?->hasAccess('platform.system.configuration.audit') ?? false,
        ];
    }

    public function name(): ?string
    {
        return $this->variable !== '' ? "Configuration: {$this->variable}" : 'Configuration variable';
    }

    public function description(): ?string
    {
        return 'Inspect and override one catalogued environment variable on this node.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.system.configuration',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        $editable = $this->value?->entry->editable() ?? false;
        $hasOverride = $this->value?->override !== null;
        $hasNode = $this->node instanceof Node;

        return [
            Button::make(__('Save override'))
                ->icon('bs.check-circle')
                ->method('save')
                ->canSee($hasNode && $editable),

            Button::make($this->value?->override?->is_active ? __('Disable override') : __('Enable override'))
                ->icon('bs.pause-circle')
                ->method('toggle')
                ->canSee($hasNode && $editable && $hasOverride),

            Button::make(__('Remove override'))
                ->icon('bs.trash')
                ->method('remove')
                ->confirm(__('Removing the override restores the environment/.env or default value at the next activation point.'))
                ->canSee($hasNode && $editable && $hasOverride),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::view('orchid.system.configuration-edit'),
        ];
    }

    public function save(string $variable, Request $request, NodeSetupService $nodes, SystemConfigOverrideStore $store): RedirectResponse
    {
        $node = $this->requireNode($nodes);
        $this->authorizeChange($request, $variable);

        $validated = $request->validate([
            'override.value' => ['present', 'string'],
            'override.reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $store->put(
                node: $node,
                name: $variable,
                input: $validated['override']['value'],
                actor: $request->user(),
                changeReason: $validated['override']['reason'] ?? null,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );
        } catch (InvalidSystemConfigValue|InvalidArgumentException $exception) {
            return redirect()
                ->route('platform.system.configuration.edit', ['variable' => $variable])
                ->withErrors(['override.value' => $exception->getMessage()])
                ->withInput();
        }

        Toast::info(__('The override for :name was saved. Check the activation requirement for when it takes effect.', ['name' => $variable]));

        return redirect()->route('platform.system.configuration.edit', ['variable' => $variable]);
    }

    public function toggle(string $variable, Request $request, NodeSetupService $nodes, SystemConfigOverrideStore $store): RedirectResponse
    {
        $node = $this->requireNode($nodes);
        $this->authorizeChange($request, $variable);

        $override = $store->overrideFor($node, $variable);

        if (! $override instanceof SystemConfigOverride) {
            Toast::warning(__('No override exists for :name.', ['name' => $variable]));

            return redirect()->route('platform.system.configuration.edit', ['variable' => $variable]);
        }

        $store->setActive(
            node: $node,
            name: $variable,
            active: ! $override->is_active,
            actor: $request->user(),
            changeReason: $request->input('override.reason'),
        );

        Toast::info(__('The override for :name is now :state.', [
            'name' => $variable,
            'state' => $override->is_active ? __('disabled') : __('enabled'),
        ]));

        return redirect()->route('platform.system.configuration.edit', ['variable' => $variable]);
    }

    public function remove(string $variable, Request $request, NodeSetupService $nodes, SystemConfigOverrideStore $store): RedirectResponse
    {
        $node = $this->requireNode($nodes);
        $this->authorizeChange($request, $variable);

        try {
            $store->remove(
                node: $node,
                name: $variable,
                actor: $request->user(),
                changeReason: $request->input('override.reason'),
            );
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('platform.system.configuration.edit', ['variable' => $variable])
                ->withErrors(['override.value' => $exception->getMessage()]);
        }

        Toast::info(__('The override for :name was removed; the environment/default value is restored at the next activation point.', ['name' => $variable]));

        return redirect()->route('platform.system.configuration.edit', ['variable' => $variable]);
    }

    private function requireNode(NodeSetupService $nodes): Node
    {
        $node = $nodes->activeNode();

        abort_unless($node instanceof Node, 422, 'This install has no configured node, so overrides cannot be stored.');

        return $node;
    }

    private function authorizeChange(Request $request, string $variable): void
    {
        abort_unless($this->canManage($request), 403, 'Managing system configuration requires the manage permission.');

        $entry = app(\App\Services\SystemConfig\EnvExampleCatalog::class)->entry($variable);

        if ($entry?->secret === true) {
            abort_unless($this->canManageSecret($request), 403, 'Changing secret configuration requires the secret-configuration permission.');
        }
    }

    private function canManage(Request $request): bool
    {
        return $request->user()?->hasAccess('platform.system.configuration.manage') ?? false;
    }

    private function canManageSecret(Request $request): bool
    {
        return $request->user()?->hasAccess('platform.system.secrets') ?? false;
    }

    /**
     * Recent audit history for this variable's override, redacted at write
     * time (SYS-015).
     *
     * @return list<AuditEvent>
     */
    private function auditEvents(Request $request, ResolvedConfigValue $value): array
    {
        if (! ($request->user()?->hasAccess('platform.system.configuration.audit') ?? false)) {
            return [];
        }

        return AuditEvent::query()
            ->where('entity_type', (new SystemConfigOverride)->getMorphClass())
            ->where(function ($query) use ($value): void {
                $query->where('after_json->name', $value->entry->name)
                    ->orWhere('before_json->name', $value->entry->name);
            })
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->all();
    }
}
