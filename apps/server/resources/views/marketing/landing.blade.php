{{--
    The public marketing surface at the deployment root (M18.23; PUBLIC-001
    through PUBLIC-005).

    Meridian's own identity, and no organization branding profile: there is no
    organization in this request to resolve one from, and this is the surface
    BRAND-003 protects (PUBLIC-001). `meridian.surface-head` loads the Meridian
    tokens and the shared surface stylesheet, exactly as the login and
    application surfaces do, and nothing here loads a branding stylesheet.

    The feature tour with its Northwood screenshots and the descriptions of the
    three platform offerings (PUBLIC-007 through PUBLIC-009) belong to Milestone
    20 and are deliberately absent rather than stubbed. What this page carries
    is what Meridian is, who it is for, and the way to get in touch.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    @include('meridian.surface-head')
    <title>Meridian — volunteer operations for events</title>
    <meta name="description" content="Meridian is volunteer operations software for events: staffing, shifts, attendance, equipment, and incident command, built to keep working when the network does not.">
</head>
<body>
    <main>
        @include('meridian.brand', ['context' => __('Volunteer operations for events')])

        <h1>Run your event's volunteer operations in one place</h1>
        <p class="lede">
            Meridian is the operations system for organizations that staff events with volunteers. Intake and
            applications, departments and teams, shifts and signup, check-in and hours, equipment, policies, and
            incident command all live in one place, so the people running an event are working from the same record
            rather than from four spreadsheets and a group chat.
        </p>

        <section class="marketing-section" aria-labelledby="who-heading">
            <h2 id="who-heading">Who it is for</h2>
            <p>
                Organizations that put on events staffed by volunteers: festivals and gatherings, community
                organizations, mutual aid and safety crews, and the departments inside them — rangers, gate, logistics,
                medical, dispatch, and whoever else your event calls them.
            </p>
        </section>

        <section class="marketing-section" aria-labelledby="offline-heading">
            <h2 id="offline-heading">Built for the field, not just the office</h2>
            <p>
                Events happen where connectivity is worst. Meridian runs on a node you can take to the site, keeps
                working while it is offline, and syncs back when it is not. It is free and open source, and you can
                host it yourself.
            </p>
        </section>

        <section class="marketing-section" id="interest" aria-labelledby="interest-heading">
            <h2 id="interest-heading">Tell us about your organization</h2>
            <p class="help">
                Writing in creates nothing and signs you up for nothing. It reaches the people who run this
                deployment, and they will get back to you.
            </p>

            @if ($errors->any())
                <ul class="errors">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('public.marketing.interest.store') }}">
                @csrf

                <label for="organization_name">
                    Organization name
                    <input
                        id="organization_name"
                        name="organization_name"
                        type="text"
                        value="{{ old('organization_name') }}"
                        required
                        maxlength="255"
                        autocomplete="organization"
                    >
                </label>

                <label for="contact_name">
                    Your name
                    <input
                        id="contact_name"
                        name="contact_name"
                        type="text"
                        value="{{ old('contact_name') }}"
                        required
                        maxlength="255"
                        autocomplete="name"
                    >
                </label>

                <label for="contact_email">
                    Your email address
                    <input
                        id="contact_email"
                        name="contact_email"
                        type="email"
                        value="{{ old('contact_email') }}"
                        required
                        maxlength="255"
                        autocomplete="email"
                    >
                </label>

                <label for="description">
                    What does your organization run?
                    <textarea
                        id="description"
                        name="description"
                        rows="6"
                        required
                        maxlength="5000"
                        aria-describedby="description-help"
                    >{{ old('description') }}</textarea>
                </label>
                <p class="help" id="description-help">
                    Your events, roughly how many volunteers you staff, and what you are hoping Meridian would take off
                    your hands. A couple of sentences is plenty.
                </p>

                {{--
                    PUBLIC-005: automated submission is deterred without a
                    challenge anybody has to solve. This field is removed from
                    the tab order, hidden from assistive technology, and labelled
                    for the rare reader who meets it anyway. `autocomplete="off"`
                    and a name no browser heuristic recognises keep autofill from
                    completing it on a real visitor's behalf.
                --}}
                <div class="trap" aria-hidden="true">
                    <label for="{{ \App\Http\Controllers\Marketing\OrganizationInterestController::TRAP_FIELD }}">
                        Leave this field empty
                        <input
                            id="{{ \App\Http\Controllers\Marketing\OrganizationInterestController::TRAP_FIELD }}"
                            name="{{ \App\Http\Controllers\Marketing\OrganizationInterestController::TRAP_FIELD }}"
                            type="text"
                            value=""
                            tabindex="-1"
                            autocomplete="off"
                        >
                    </label>
                </div>

                <button type="submit">Send</button>
            </form>
        </section>

        <section class="marketing-section" aria-labelledby="already-heading">
            <h2 id="already-heading">Already working an event?</h2>
            <p>
                <a href="{{ route('login') }}">Sign in</a> if your organization already uses this deployment, or
                <a href="{{ route('applicant-portal.request') }}">find an application you have submitted</a>.
            </p>
        </section>

        @include('meridian.footer')
    </main>
</body>
</html>
