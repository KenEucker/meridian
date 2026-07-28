<!DOCTYPE html>
<html lang="en">
<head>
    @include('meridian.surface-head')
    <title>Sign out of Meridian</title>
</head>
<body>
    <main>
        @include('meridian.brand', ['context' => __('Sign out')])

        <h1>Sign out</h1>
        <p class="lede">Signed in as <strong>{{ $email }}</strong>.</p>

        <form method="POST" action="{{ route('logout.destroy') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>

        <p><a href="{{ route('home') }}">Back to home</a></p>

        @include('meridian.footer')
    </main>
</body>
</html>
