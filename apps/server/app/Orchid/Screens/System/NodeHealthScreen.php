<?php

declare(strict_types=1);

namespace App\Orchid\Screens\System;

use App\Models\NodeHealthReport;
use App\Services\Node\NodeSetupService;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * System -> Node Health: the latest sanitized health summary from every known
 * node (technical spec 22A.11; SYS-037, SYS-040).
 *
 * On central this shows the fleet; on any other node it shows at least the
 * node's own latest report, so the screen stays useful offline. A report
 * older than the staleness window is labelled stale rather than silently
 * trusted, and an on-site node whose latest report says it is queuing
 * operations is expected-offline, not failed (SYS-036, SYS-040).
 */
class NodeHealthScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(NodeSetupService $nodes): iterable
    {
        return [
            'node' => $nodes->activeNode(),
            'reports' => NodeHealthReport::query()
                ->with('node')
                ->orderBy('node_name')
                ->get(),
        ];
    }

    public function name(): ?string
    {
        return 'Node Health';
    }

    public function description(): ?string
    {
        return 'Latest sanitized health reports from this node and its paired nodes.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.system.diagnostics',
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::view('orchid.system.node-health'),
        ];
    }
}
