<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Services\Console\ConsoleAttention;
use App\Services\Console\ConsoleOrientation;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * The God Mode landing screen (GOD-001 through GOD-011; technical spec 22.5).
 *
 * This replaces the administrative framework's welcome partial and its
 * "Welcome to your Orchid application" description. The console is Meridian's
 * repair surface, so its landing screen orients an operator in Meridian and
 * then points at whatever currently needs a human.
 *
 * The screen only reads. Nothing here mutates state as a side effect of being
 * displayed (GOD-010).
 */
class PlatformScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(ConsoleOrientation $orientation, ConsoleAttention $attention): iterable
    {
        return [
            'summary' => $orientation->summary(),
            'boundary' => $orientation->boundary(),
            'topics' => $orientation->topics(),
            'attention' => $attention->describe(),
        ];
    }

    public function name(): ?string
    {
        return 'Meridian God Mode';
    }

    public function description(): ?string
    {
        return 'Repair and break-glass tooling for a Meridian deployment.';
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
            Layout::view('orchid.console.orientation'),
            Layout::view('orchid.console.attention'),
        ];
    }
}
