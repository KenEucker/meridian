<!DOCTYPE html>
<html lang="en">
<head>
    @include('meridian.surface-head')
    <title>Find your applications</title>
</head>
<body>
    <main>
        @include('meridian.brand', ['context' => __('Applications')])

        <h1>Find your applications</h1>
        <p class="lede">
            Enter the email address you applied with and we will send you a link to your applications. You do not need
            an account, and the link signs you in to nothing.
        </p>

        @if (session('status'))
            <p class="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <ul class="errors">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @if ($openPortalEmail)
            <p class="status">
                You are already looking at the applications for {{ $openPortalEmail }}.
                <a href="{{ route('applicant-portal.show') }}">Open them</a>.
            </p>
        @endif

        <form method="POST" action="{{ route('applicant-portal.request.store') }}">
            @csrf
            <label for="email">
                Email address
                <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email">
            </label>
            <button type="submit">Email me a link</button>
        </form>

        @include('meridian.footer')
    </main>
</body>
</html>
