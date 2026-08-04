<!DOCTYPE html>
<html lang="en">
<head>
    @include('meridian.surface-head')
    <title>Check your email</title>
</head>
<body>
    <main>
        @include('meridian.brand', ['context' => __('Applications')])

        <h1>Check your email</h1>

        {{--
            The sentence is conditional in its wording and unconditional in when
            it appears (APP-014). It says the same thing for an address with
            applications, an address with none, and an address whose only
            application was auto-rejected, because a page that said anything
            more precise would answer a question the applicant is not the only
            person who could ask.
        --}}
        <p class="lede">
            If <strong>{{ $email }}</strong> has any applications, a link to them is on its way. It expires shortly, so
            open it when it arrives.
        </p>

        <p><a href="{{ route('applicant-portal.request') }}">Use a different address</a></p>

        @include('meridian.footer')
    </main>
</body>
</html>
