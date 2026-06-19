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

        <form method="POST" action="{{ route('public.events.apply.store', $event->applyRouteParameters()) }}">
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

            <button type="submit">Submit application</button>
        </form>
    </main>
</body>
</html>
