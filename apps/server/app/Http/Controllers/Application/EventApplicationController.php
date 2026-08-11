<?php

namespace App\Http\Controllers\Application;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Organization;
use App\Services\Application\ApplicationApplicantAccess;
use App\Services\Application\DuplicateApplicationException;
use App\Services\Application\EventApplicationService;
use App\Services\Application\EventNotOpenForApplicationsException;
use App\Services\Application\ApplicationWithdrawalException;
use App\Services\Organizations\OrganizationHostUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public event application form (UI implementation contract screen `public.apply`,
 * route `public.events.apply`). Renders the form and records a Submitted
 * application (requirements APP-001 through APP-004, section 3.10, section 5.2).
 *
 * Serves at the path form and at the organization subdomain form alike; its
 * redirects go through {@see OrganizationHostUrls} so a visitor who arrived on
 * an organization subdomain stays on it (M19.9; technical spec 8.7).
 */
class EventApplicationController extends Controller
{
    public function __construct(
        private readonly EventApplicationService $applications,
        private readonly OrganizationHostUrls $urls,
    ) {}

    public function create(Organization $organization, Event $event): View
    {
        if ($event->isArchived()) {
            return view('application.closed', ['event' => $event]);
        }

        return view('application.apply', [
            'event' => $event,
            'eligibleDepartmentInterests' => $this->applications->eligibleDepartmentInterests($event),
        ]);
    }

    public function store(Request $request, Organization $organization, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            'applicant_legal_name' => ['required', 'string', 'max:255'],
            'applicant_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'department_interest_ids' => ['sometimes', 'array'],
            'department_interest_ids.*' => ['string', 'uuid', 'distinct'],
        ]);

        try {
            $application = $this->applications->submit(
                $event,
                $validated['applicant_legal_name'],
                $validated['applicant_email'],
                $request->user(),
                $validated['department_interest_ids'] ?? [],
            );
        } catch (EventNotOpenForApplicationsException $exception) {
            return back()
                ->withInput()
                ->withErrors(['applicant_email' => $exception->getMessage()]);
        } catch (DuplicateApplicationException $exception) {
            return back()
                ->withInput()
                ->withErrors(['applicant_email' => $exception->getMessage()]);
        }

        return redirect()
            ->to($this->urls->route('public.events.apply.submitted', $event->applyRouteParameters()))
            ->with('application_submitted', true)
            ->with('submitted_application_id', $application->id);
    }

    public function submitted(Request $request, Organization $organization, Event $event): View|RedirectResponse
    {
        if (! $request->session()->get('application_submitted')) {
            return redirect()->to($this->urls->route('public.events.apply', $event->applyRouteParameters()));
        }

        $application = null;
        $submittedApplicationId = $request->session()->get('submitted_application_id');

        if (is_string($submittedApplicationId) && $submittedApplicationId !== '') {
            $application = EventApplication::query()
                ->whereKey($submittedApplicationId)
                ->where('event_id', $event->id)
                ->first();
        }

        $canWithdraw = $application instanceof EventApplication
            && app(ApplicationApplicantAccess::class)->canWithdrawApplication(
                $request->user(),
                $application,
                $submittedApplicationId,
            );

        return view('application.submitted', [
            'event' => $event,
            'application' => $application,
            'canWithdraw' => $canWithdraw,
            'withdrawn' => $request->session()->get('application_withdrawn') === true,
        ]);
    }

    public function withdraw(
        Request $request,
        Organization $organization,
        Event $event,
        EventApplication $application,
    ): RedirectResponse {
        abort_unless($application->event_id === $event->id, 404);

        $submittedApplicationId = $request->session()->get('submitted_application_id');
        abort_unless(app(ApplicationApplicantAccess::class)->canWithdrawApplication(
            $request->user(),
            $application,
            is_string($submittedApplicationId) ? $submittedApplicationId : null,
        ), 403);

        try {
            $this->applications->withdraw($application, $request->user());
        } catch (ApplicationWithdrawalException $exception) {
            return back()->withErrors(['withdraw' => $exception->getMessage()]);
        }

        return redirect()
            ->to($this->urls->route('public.events.apply.submitted', $event->applyRouteParameters()))
            ->with('application_submitted', true)
            ->with('submitted_application_id', $application->id)
            ->with('application_withdrawn', true);
    }
}
