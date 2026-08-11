<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Apply to {{ $event->name }}</title>
</head>
<body>
    <main>
        <h1>Apply to {{ $event->name }}</h1>
        <p>Submit an application for this event. Approval and any department or team assignment happen after review.</p>

        @if ($errors->any())
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        {{--
            Host-aware (M19.9): on the organization's subdomain the action is
            the subdomain form of the same route, so submitting keeps the
            visitor on the host they arrived at.
        --}}
        <form method="POST" action="{{ app(\App\Services\Organizations\OrganizationHostUrls::class)->route('public.events.apply.store', $event->applyRouteParameters()) }}">
            @csrf

            <label for="applicant_legal_name">Legal name</label>
            <input
                id="applicant_legal_name"
                name="applicant_legal_name"
                type="text"
                value="{{ old('applicant_legal_name') }}"
                required
                maxlength="255"
                autocomplete="name"
            >

            <label for="applicant_email">Email address</label>
            <input
                id="applicant_email"
                name="applicant_email"
                type="email"
                value="{{ old('applicant_email') }}"
                required
                maxlength="255"
                autocomplete="email"
            >

            @if ($eligibleDepartmentInterests->isNotEmpty())
                <fieldset>
                    <legend>Department interest</legend>
                    <p id="department-interest-help">
                        Optional and non-binding. Select any departments you are open to working with; leaving every option unchecked means no preference and is not an assignment or membership.
                    </p>

                    @foreach ($eligibleDepartmentInterests as $department)
                        <div>
                            <input
                                id="department_interest_{{ $department->id }}"
                                name="department_interest_ids[]"
                                type="checkbox"
                                value="{{ $department->id }}"
                                aria-describedby="department-interest-help"
                                @checked(in_array($department->id, old('department_interest_ids', []), true))
                            >
                            <label for="department_interest_{{ $department->id }}">{{ $department->name }}</label>
                        </div>
                    @endforeach
                </fieldset>
            @endif

            <button type="submit">Submit application</button>
        </form>

        {{--
            APP-012 puts the request for a portal link on the public application
            surface, which is where somebody wondering what became of an earlier
            application comes back to.
        --}}
        <p>
            Applied already?
            <a href="{{ route('applicant-portal.request') }}">Find your applications</a>.
        </p>
    </main>
</body>
</html>
