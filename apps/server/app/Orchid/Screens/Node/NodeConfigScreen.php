<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Node;

use App\Models\Node;
use App\Services\Node\NodeConfigResolver;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class NodeConfigScreen extends Screen
{
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
        return 'Source display';
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
        return [];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::view('orchid.node-config'),
        ];
    }
}
