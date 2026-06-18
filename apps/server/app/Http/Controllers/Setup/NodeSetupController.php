<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\Node\NodeAlreadyConfiguredException;
use App\Services\Node\NodeSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NodeSetupController extends Controller
{
    public function __construct(private readonly NodeSetupService $setup) {}

    public function show(): View
    {
        return view('setup.node', [
            'node' => $this->setup->activeNode(),
            'roles' => Node::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'node_name' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i',
            ],
            'node_role' => ['required', Rule::in(Node::ROLES)],
            'central_node_url' => ['nullable', 'url', 'max:2048'],
        ]);

        try {
            $this->setup->setupFirstNode(
                nodeName: $validated['node_name'],
                nodeRole: $validated['node_role'],
                centralNodeUrl: $validated['central_node_url'] ?? null,
                updatedBy: $request->user(),
            );
        } catch (NodeAlreadyConfiguredException) {
            return redirect()
                ->route('setup.show')
                ->withErrors(['setup' => 'This Meridian install already has an active node.']);
        }

        return redirect()
            ->route('setup.show')
            ->with('status', 'Node setup complete.');
    }
}
