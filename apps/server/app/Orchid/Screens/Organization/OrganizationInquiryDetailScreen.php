<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Organization;

use App\Models\OrganizationInquiry;
use App\Models\User;
use App\Orchid\Layouts\Organization\OrganizationInquiryDetailLayout;
use App\Orchid\Layouts\Organization\OrganizationInquiryReviewLayout;
use App\Services\Marketing\OrganizationInterestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * One organization inquiry, and what the console has done about it (M18.23;
 * PUBLIC-004).
 *
 * There is no "create organization from this inquiry" action, and its absence
 * is the requirement rather than an omission. PUBLIC-004 keeps organization
 * creation a God Mode action; a button here would make it a consequence of
 * reading a message somebody on the internet sent, with that message's
 * unverified organization name filled in for them. An operator who decides to
 * go ahead creates the organization on the Organizations screen, from what they
 * agreed rather than from what was typed into a public form.
 */
class OrganizationInquiryDetailScreen extends Screen
{
    /**
     * @var OrganizationInquiry
     */
    public $inquiry;

    /**
     * @return array<string, mixed>
     */
    public function query(OrganizationInquiry $inquiry): iterable
    {
        $inquiry->load('reviewedBy');

        return [
            'inquiry' => $inquiry,
            'review' => ['review_notes' => $inquiry->review_notes],
            'status_label' => $inquiry->statusLabel(),
            'submitted_at_display' => $this->formatTimestamp($inquiry->submitted_at),
            'reviewed_at_display' => $this->formatTimestamp($inquiry->reviewed_at),
            'reviewed_by_display' => $inquiry->reviewedBy?->name ?? __('Not reviewed'),
        ];
    }

    public function name(): ?string
    {
        return 'Organization Inquiry';
    }

    public function description(): ?string
    {
        return 'An organization that wrote in from the marketing surface. Nothing here creates an organization, a user, or a staff record.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            OrganizationInquiryListScreen::PERMISSION,
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        $status = $this->inquiry?->status;

        return [
            Link::make(__('Back'))
                ->icon('bs.arrow-left-circle')
                ->route('platform.organization-inquiries'),

            Button::make(__('Mark reviewed'))
                ->icon('bs.check-circle')
                ->method('markReviewed')
                ->canSee($status !== OrganizationInquiry::STATUS_REVIEWED),

            Button::make(__('Close'))
                ->icon('bs.archive')
                ->method('close')
                ->confirm(__('Close this inquiry? It stays on record and can be reopened.'))
                ->canSee($status !== OrganizationInquiry::STATUS_CLOSED),

            Button::make(__('Reopen'))
                ->icon('bs.arrow-counterclockwise')
                ->method('reopen')
                ->canSee($status === OrganizationInquiry::STATUS_CLOSED),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(OrganizationInquiryDetailLayout::class)
                ->title(__('What they wrote'))
                ->description(__('Submitted from the public marketing surface. None of it is verified, including the email address.')),

            Layout::block(OrganizationInquiryReviewLayout::class)
                ->title(__('Review'))
                ->description(__('Record what happened next. Saving a note marks the inquiry reviewed if it was still new.'))
                ->commands(
                    Button::make(__('Save notes'))
                        ->icon('bs.pencil')
                        ->method('saveNotes'),
                ),
        ];
    }

    public function markReviewed(Request $request, OrganizationInquiry $inquiry, OrganizationInterestService $interest): RedirectResponse
    {
        return $this->applyStatus($request, $inquiry, $interest, OrganizationInquiry::STATUS_REVIEWED, __('Inquiry marked reviewed.'));
    }

    public function close(Request $request, OrganizationInquiry $inquiry, OrganizationInterestService $interest): RedirectResponse
    {
        return $this->applyStatus($request, $inquiry, $interest, OrganizationInquiry::STATUS_CLOSED, __('Inquiry closed. It stays on record.'));
    }

    public function reopen(Request $request, OrganizationInquiry $inquiry, OrganizationInterestService $interest): RedirectResponse
    {
        return $this->applyStatus($request, $inquiry, $interest, OrganizationInquiry::STATUS_REVIEWED, __('Inquiry reopened.'));
    }

    /**
     * Save the note without deciding anything, except that an inquiry nobody
     * had read is one somebody has now read.
     */
    public function saveNotes(Request $request, OrganizationInquiry $inquiry, OrganizationInterestService $interest): RedirectResponse
    {
        $status = $inquiry->isNew() ? OrganizationInquiry::STATUS_REVIEWED : (string) $inquiry->status;

        return $this->applyStatus($request, $inquiry, $interest, $status, __('Review notes saved.'));
    }

    /**
     * The screen gates on the permission, and every method checks it again: a
     * screen method is its own request and does not pass back through the
     * screen's own authorization.
     */
    private function applyStatus(
        Request $request,
        OrganizationInquiry $inquiry,
        OrganizationInterestService $interest,
        string $status,
        string $message,
    ): RedirectResponse {
        $reviewer = $this->authorizedOperator();

        $validated = $request->validate([
            'review.review_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $interest->review(
            $inquiry,
            $status,
            $validated['review']['review_notes'] ?? null,
            $reviewer,
        );

        Toast::info($message);

        return redirect()->route('platform.organization-inquiries.show', $inquiry->getKey());
    }

    private function authorizedOperator(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->hasAccess(OrganizationInquiryListScreen::PERMISSION), 403);

        return $user;
    }

    private function formatTimestamp(?\DateTimeInterface $timestamp): string
    {
        if ($timestamp === null) {
            return __('Not recorded');
        }

        return $timestamp->format('Y-m-d H:i:s T');
    }
}
