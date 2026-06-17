<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Meridian') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
        }
        main {
            max-width: 40rem;
            padding: 2rem;
            text-align: center;
        }
        h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
        p { line-height: 1.6; color: #94a3b8; }
        code { color: #cbd5e1; }
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
