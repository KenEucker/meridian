<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Meridian') }}</title>
    {{-- Shared Meridian semantic UI tokens (M2.5), mirrored from
         packages/ui-tokens/tokens.css. --}}
    <link rel="stylesheet" href="{{ asset('css/meridian-tokens.css') }}">
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--m-font-body);
            background: var(--m-surface-app);
            color: var(--m-text-primary);
        }
        main {
            max-width: 40rem;
            padding: var(--m-space-8);
            text-align: center;
        }
        h1 { font-size: var(--m-text-xl); margin-bottom: var(--m-space-2); }
        p { line-height: 1.6; color: var(--m-text-secondary); }
        code { color: var(--m-text-primary); }
    </style>
</head>
<body>
    <main>
        <h1>Meridian server</h1>
        <p>
            The Laravel server scaffold is running. No Meridian product
            workflows are implemented yet; database configuration, the Orchid
            admin panel, and the health endpoint arrive in later Alpha 1 tasks.
        </p>
    </main>
</body>
</html>
