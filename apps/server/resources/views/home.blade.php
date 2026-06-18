<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meridian</title>
</head>
<body>
    <main>
        <h1>Signed in</h1>
        <p>You are signed in as {{ auth()->user()->email }}.</p>
        <p><a href="{{ route('logout') }}">Sign out</a></p>
    </main>
</body>
</html>
