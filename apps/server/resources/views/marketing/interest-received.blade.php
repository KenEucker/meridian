{{--
    Confirmation for an organization interest submission (M18.23; PUBLIC-002,
    PUBLIC-003, PUBLIC-004).

    It is careful about what it promises. Nothing was created — PUBLIC-003 —
    and no organization comes into existence until a God Mode operator makes one
    — PUBLIC-004 — so the page says a person will read it rather than implying
    an account is waiting.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    @include('meridian.surface-head')
    <title>Thank you — Meridian</title>
</head>
<body>
    <main>
        @include('meridian.brand', ['context' => __('Volunteer operations for events')])

        <h1>Thank you</h1>
        <p class="lede">
            We have your note about {{ $organizationName }}. Somebody who runs this Meridian deployment will read it
            and get back to you at the address you gave us.
        </p>

        <div class="panel">
            <h2>What happens next</h2>
            <p>
                Nothing has been created yet — no account, no organization, and no sign-up. Setting an organization up
                on Meridian is a deliberate step somebody takes with you, once you have both decided it is a fit.
            </p>
        </div>

        <p class="alternatives">
            <a href="{{ route('public.marketing.landing') }}">Back to the start</a>
        </p>

        @include('meridian.footer')
    </main>
</body>
</html>
