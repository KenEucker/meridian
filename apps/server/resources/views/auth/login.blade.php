<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in to Meridian</title>
</head>
<body>
    <main>
        <h1>Sign in to Meridian</h1>
        <p>Enter your email address and we will send you a magic login link.</p>

        @if (session('status'))
            <p>{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('auth.magic-link.store') }}">
            @csrf
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
            <button type="submit">Send login link</button>
        </form>

        <p>
            <a href="{{ route('auth.google.redirect') }}">Continue with Google</a>
        </p>

        <p>
            <a href="{{ route('auth.discord.redirect') }}">Continue with Discord</a>
        </p>
    </main>
</body>
</html>
