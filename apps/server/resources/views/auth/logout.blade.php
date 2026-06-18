<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign out of Meridian</title>
</head>
<body>
    <main>
        <h1>Sign out</h1>
        <p>Signed in as <strong>{{ $email }}</strong>.</p>

        <form method="POST" action="{{ route('logout.destroy') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>

        <p><a href="{{ route('home') }}">Back to home</a></p>
    </main>
</body>
</html>
