<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Application;

use App\Models\EventApplication;
use App\Models\User;
use App\Orchid\Layouts\Application\ApplicationDetailLayout;
use App\Services\Application\ApplicationApprovalException;
use App\Services\Application\ApplicationReviewAccess;
use App\Services\Application\EventApplicationService;
use Illuminate\Http\RedirectResponse;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ApplicationDetailScreen extends Screen
{
    /**
     * @var EventApplication
     */
    public $application;

    /**
     * @return array<string, mixed>
     */
    public function query(EventApplication $application): iterable
    {
        $user = request()->user();
        abort_unless($user instanceof User, 403);

        $application->load(['event', 'organization', 'reviewedBy', 'staff', 'departmentInterests']);
        abort_unless(app(ApplicationReviewAccess::class)->canViewApplication($user, $application), 403);

        return [
            'application' => $application,
            'event_name' => $application->event?->name ?? __('Not configured'),
            'organization_name' => $application->organization?->name ?? __('Not configured'),
            'status_label' => $application->statusLabel(),
            'department_interest_display' => $application->departmentInterestDisplay(),
            'submitted_at_display' => $this->formatTimestamp($application->submitted_at),
            'reviewed_at_display' => $this->formatTimestamp($application->reviewed_at),
            'reviewed_by_display' => $application->reviewedBy?->name ?? __('Not reviewed'),
            'withdrawn_at_display' => $this->formatTimestamp($application->withdrawn_at),
            'staff_display' => $application->staff?->legal_name ?? __('Not matched'),
        ];
    }

    public function name(): ?string
    {
        return 'Review Application';
    }

    public function description(): ?string
    {
        return 'Organization-level application review detail.';
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        $user = request()->user();
        $canApprove = $user instanceof User
            && $this->application?->isSubmitted() === true
            && app(ApplicationReviewAccess::class)->canReviewApplications($user);

        return [
            Link::make(__('Back'))
                ->icon('bs.arrow-left-circle')
                ->route('platform.applications'),

            Button::make(__('Approve'))
                ->icon('bs.check-circle')
                ->method('approve')
                ->confirm(__('Approve this application at the organization level and create Prospective staff status?'))
                ->canSee($canApprove),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(ApplicationDetailLayout::class)
                ->title(__('Application'))
                ->description(__('Review applicant identity, event scope, and current status. Approval creates Prospective organization status; reject and defer actions are delivered in later tasks.')),
        ];
    }

    public function approve(EventApplication $application): RedirectResponse
    {
        $user = request()->user();
        abort_unless($user instanceof User, 403);
        abort_unless(app(ApplicationReviewAccess::class)->canReviewApplications($user), 403);

        try {
            app(EventApplicationService::class)->approve($application, $user);
            Toast::info(__('Application was approved.'));
        } catch (ApplicationApprovalException $exception) {
            Toast::warning(__($exception->getMessage()));
        }

        return redirect()->route('platform.applications.show', $application);
    }

    private function formatTimestamp(?\DateTimeInterface $timestamp): string
    {
        if ($timestamp === null) {
            return __('Not set');
        }

        return $timestamp->format('Y-m-d H:i:s T');
    }
}
