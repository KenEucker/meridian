<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Application;

use App\Models\EventApplication;
use App\Models\User;
use App\Orchid\Layouts\Application\ApplicationFiltersLayout;
use App\Orchid\Layouts\Application\ApplicationListLayout;
use App\Services\Application\ApplicationReviewAccess;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class ApplicationListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        $user = request()->user();
        abort_unless($user instanceof User, 403);

        $access = app(ApplicationReviewAccess::class);
        abort_unless($access->canReviewApplications($user) || $access->hasDepartmentLeadVisibility($user), 403);

        return [
            'applications' => $access->scopeVisibleApplications(
                EventApplication::query()->with(['event', 'organization', 'departmentInterests']),
                $user,
            )
                ->filters(ApplicationFiltersLayout::class)
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
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            ApplicationFiltersLayout::class,
            ApplicationListLayout::class,
        ];
    }
}
