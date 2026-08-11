<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application submitted</title>
</head>
<body>
    <main>
        @if ($withdrawn ?? false)
            <h1>Application withdrawn</h1>
            <p>Your application to {{ $event->name }} has been withdrawn.</p>
        @else
            <h1>Application submitted</h1>
            <p>Your application to {{ $event->name }} has been received.</p>
        @endif

        @if (($canWithdraw ?? false) && $application instanceof \App\Models\EventApplication)
            <form method="post" action="{{ app(\App\Services\Organizations\OrganizationHostUrls::class)->route('public.events.apply.withdraw', array_merge($event->applyRouteParameters(), ['application' => $application->id])) }}">
                @csrf
                <button type="submit">Withdraw application</button>
            </form>
        @endif

        @error('withdraw')
            <p>{{ $message }}</p>
        @enderror

        {{--
            The session that lets this page offer a withdrawal is this browser's
            alone and does not survive it (APP-004). The portal is how the same
            applicant reaches the same application from anywhere else (APP-012).
        --}}
        <p>
            You can come back to this and any other application later:
            <a href="{{ route('applicant-portal.request') }}">find your applications</a>.
        </p>
    </main>
</body>
</html>
