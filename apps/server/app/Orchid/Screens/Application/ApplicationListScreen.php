<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Application;

use App\Models\EventApplication;
use App\Orchid\Layouts\Application\ApplicationListLayout;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class ApplicationListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'applications' => EventApplication::query()
                ->with(['event', 'organization'])
                ->filters()
                ->defaultSort('submitted_at', 'desc')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Applications';
    }

    public function description(): ?string
    {
        return 'Event application intake records awaiting organizer review.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.applications',
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            ApplicationListLayout::class,
        ];
    }
}
