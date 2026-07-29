<?php

declare(strict_types=1);

namespace App\Orchid\Screens\System;

use App\Services\Diagnostics\DiagnosticRunner;
use App\Services\Diagnostics\DiagnosticsExport;
use App\Services\Diagnostics\DiagnosticStatus;
use App\Services\Node\NodeSetupService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * System -> Diagnostics: is this Meridian node operating correctly
 * (technical spec 22A.8, 22A.9; SYS-029 through SYS-036)?
 *
 * Checks run when the screen loads and on explicit refresh — there is no
 * background polling. Configuration values are not shown here; that is the
 * configuration screen's job, and the separation is what keeps this page
 * exportable (SYS-028).
 */
class SystemDiagnosticsScreen extends Screen
{
    /**
     * @var bool
     */
    public $canExport = false;

    /**
     * @return array<string, mixed>
     */
    public function query(Request $request, DiagnosticRunner $runner, NodeSetupService $nodes): iterable
    {
        $report = $runner->run();

        $statusFilter = (string) $request->query('status', '');
        $categoryFilter = (string) $request->query('category', '');
        $requiredFilter = (string) $request->query('required', '');

        $checks = array_values(array_filter(
            $report->checks,
            static function ($check) use ($statusFilter, $categoryFilter, $requiredFilter): bool {
                if ($statusFilter !== '' && $check->result->status !== $statusFilter) {
                    return false;
                }

                if ($categoryFilter !== '' && $check->category !== $categoryFilter) {
                    return false;
                }

                return match ($requiredFilter) {
                    'required' => $check->required,
                    'optional' => ! $check->required,
                    default => true,
                };
            },
        ));

        return [
            'node' => $nodes->activeNode(),
            'report' => $report,
            'checks' => $checks,
            'statuses' => DiagnosticStatus::ALL,
            'categories' => array_keys($report->byCategory()),
            'filters' => [
                'status' => $statusFilter,
                'category' => $categoryFilter,
                'required' => $requiredFilter,
            ],
            'canExport' => $request->user()?->hasAccess('platform.system.diagnostics.export') ?? false,
        ];
    }

    public function name(): ?string
    {
        return 'System Diagnostics';
    }

    public function description(): ?string
    {
        return 'Whether this Meridian node is operating correctly: services, storage, queues, sync, and security warnings.';
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
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Refresh'))
                ->icon('bs.arrow-clockwise')
                ->method('refresh'),

            Button::make(__('Export sanitized bundle'))
                ->icon('bs.file-earmark-arrow-down')
                ->method('export')
                ->rawClick()
                ->canSee($this->canExport),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::view('orchid.system.diagnostics'),
        ];
    }

    public function refresh(): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('platform.system.diagnostics');
    }

    /**
     * Download the sanitized diagnostics bundle (technical spec 22A.10;
     * SYS-034, SYS-035). The export permission is enforced here as well as in
     * the command bar, because hiding a button is not authorization.
     */
    public function export(Request $request, DiagnosticsExport $export): Response
    {
        abort_unless(
            $request->user()?->hasAccess('platform.system.diagnostics.export') ?? false,
            403,
            'Exporting diagnostics requires the export permission.',
        );

        $bundle = $export->build();

        return response(
            json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            200,
            [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="meridian-diagnostics-'.now()->format('Ymd-His').'.json"',
            ],
        );
    }
}
