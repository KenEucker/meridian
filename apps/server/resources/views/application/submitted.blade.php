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
            <form method="post" action="{{ route('public.events.apply.withdraw', array_merge($event->applyRouteParameters(), ['application' => $application->id])) }}">
                @csrf
                <button type="submit">Withdraw application</button>
            </form>
        @endif

        @error('withdraw')
            <p>{{ $message }}</p>
        @enderror
    </main>
</body>
</html>
