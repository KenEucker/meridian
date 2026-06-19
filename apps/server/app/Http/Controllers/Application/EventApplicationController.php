<?php

namespace App\Http\Controllers\Application;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Application\DuplicateApplicationException;
use App\Services\Application\EventApplicationService;
use App\Services\Application\EventNotOpenForApplicationsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public event application form (UI implementation contract screen `public.apply`,
 * route `public.events.apply`). Renders the form and records a Submitted
 * application (requirements APP-001 through APP-004, section 3.10, section 5.2).
 */
class EventApplicationController extends Controller
{
    public function __construct(private readonly EventApplicationService $applications) {}

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
            $this->applications->submit(
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
            ->route('public.events.apply.submitted', $event->applyRouteParameters())
            ->with('application_submitted', true);
    }

    public function submitted(Request $request, Organization $organization, Event $event): View|RedirectResponse
    {
        if (! $request->session()->get('application_submitted')) {
            return redirect()->route('public.events.apply', $event->applyRouteParameters());
        }

        return view('application.submitted', ['event' => $event]);
    }
}
