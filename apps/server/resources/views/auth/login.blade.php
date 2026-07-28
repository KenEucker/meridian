<!DOCTYPE html>
<html lang="en">
<head>
    @include('meridian.surface-head')
    <title>Sign in to Meridian</title>
</head>
<body>
    <main>
        @include('meridian.brand', ['context' => __('Sign in')])

        <h1>Sign in to Meridian</h1>
        <p class="lede">Enter your email address and we will send you a magic login link.</p>

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

        <form method="POST" action="{{ route('auth.magic-link.store') }}">
            @csrf
            <label for="email">
                Email address
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
            </label>
            <button type="submit">Send login link</button>
        </form>

        <p class="alternatives">
            <a href="{{ route('auth.google.redirect') }}">Continue with Google</a>
            <a href="{{ route('auth.discord.redirect') }}">Continue with Discord</a>
        </p>

        @include('meridian.footer')
    </main>
</body>
</html>
