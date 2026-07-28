<!DOCTYPE html>
<html lang="en">
<head>
    @include('meridian.surface-head')
    <title>Check your email</title>
</head>
<body>
    <main>
        @include('meridian.brand', ['context' => __('Sign in')])

        <h1>Check your email</h1>
        <p class="lede">If an account can receive mail at <strong>{{ $email }}</strong>, a login link is on its way.</p>
        <p><a href="{{ route('login') }}">Back to sign in</a></p>

        @include('meridian.footer')
    </main>
</body>
</html>
