<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Check your email</title>
</head>
<body>
    <main>
        <h1>Check your email</h1>
        <p>If an account can receive mail at <strong>{{ $email }}</strong>, a login link is on its way.</p>
        <p><a href="{{ route('login') }}">Back to sign in</a></p>
    </main>
</body>
</html>
