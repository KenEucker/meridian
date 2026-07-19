<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Meridian') }}</title>
    <link rel="stylesheet" href="{{ asset('css/meridian-tokens.css') }}">
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: var(--m-font-body);
            background: var(--m-surface-app);
            color: var(--m-text-primary);
        }
        main {
            width: min(80vw, 40rem);
            padding: var(--m-space-8) 0;
        }
        h1 {
            margin: 0 0 var(--m-space-2);
            font-family: var(--m-font-heading);
            font-size: var(--m-text-xl);
        }
        p {
            margin: 0;
            color: var(--m-text-secondary);
            line-height: 1.6;
        }
        code {
            color: var(--m-text-primary);
        }
    </style>
</head>
<body>
    <main>
        <h1>Meridian client build unavailable</h1>
        <p>
            Build the shared Vue client with <code>corepack pnpm run client:build</code>
            so Laravel can serve the product app from <code>/</code>.
        </p>
    </main>
</body>
</html>
