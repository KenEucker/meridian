<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Application;

use App\Models\EventApplication;
use App\Orchid\Layouts\Application\ApplicationDetailLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

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
        $application->load(['event', 'organization', 'reviewedBy', 'staff']);

        return [
            'application' => $application,
            'event_name' => $application->event?->name ?? __('Not configured'),
            'organization_name' => $application->organization?->name ?? __('Not configured'),
            'status_label' => $application->statusLabel(),
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
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.applications',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Back'))
                ->icon('bs.arrow-left-circle')
                ->route('platform.applications'),
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
                ->description(__('Review applicant identity, event scope, and current status. Approval and rejection actions are delivered in later tasks.')),
        ];
    }

    private function formatTimestamp(?\DateTimeInterface $timestamp): string
    {
        if ($timestamp === null) {
            return __('Not set');
        }

        return $timestamp->format('Y-m-d H:i:s T');
    }
}
