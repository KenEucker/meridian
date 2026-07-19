<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meridian Node Setup</title>
    <link rel="stylesheet" href="{{ asset('css/meridian-tokens.css') }}">
    <style>
        :root {
            color-scheme: light dark;
            --setup-surface-app: var(--m-surface-app, #f6f1e8);
            --setup-surface-card: var(--m-surface-raised, #ffffff);
            --setup-surface-control: var(--m-surface-base, #fffcf6);
            --setup-text-primary: var(--m-text-primary, #151a1f);
            --setup-border-subtle: var(--m-border-subtle, #ded6c8);
            --setup-border-strong: var(--m-border-strong, #a99e88);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --setup-surface-app: var(--m-surface-app, #151a1f);
                --setup-surface-card: var(--m-surface-raised, #232c35);
                --setup-surface-control: var(--m-surface-base, #1b222a);
                --setup-text-primary: var(--m-text-primary, #f6f1e8);
                --setup-border-subtle: var(--m-border-subtle, #2a343e);
                --setup-border-strong: var(--m-border-strong, #4a5765);
            }
        }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--m-font-body, system-ui, sans-serif);
            background: var(--setup-surface-app);
            color: var(--setup-text-primary);
        }
        main {
            width: min(100% - 2rem, 42rem);
            margin: 0 auto;
            padding: 3rem 0;
        }
        h1 {
            margin: 0 0 1rem;
            font-size: var(--m-text-xl, 1.5rem);
            letter-spacing: 0;
        }
        h2 {
            margin: 0;
            font-size: var(--m-text-lg, 1.25rem);
        }
        .lede,
        .help {
            margin: 0 0 1rem;
            color: var(--m-text-secondary, #405665);
        }
        .help {
            font-size: var(--m-text-sm, .875rem);
        }
        form,
        .panel {
            display: grid;
            gap: 1rem;
            padding: 1.25rem;
            border: 1px solid var(--setup-border-subtle);
            border-radius: 8px;
            background: var(--setup-surface-card);
        }
        label {
            display: grid;
            gap: .35rem;
            font-weight: 600;
        }
        input,
        select {
            min-height: 2.5rem;
            padding: .45rem .6rem;
            border: 1px solid var(--setup-border-strong);
            border-radius: 6px;
            color: var(--setup-text-primary);
            background: var(--setup-surface-control);
        }
        button {
            width: fit-content;
            min-height: 2.5rem;
            padding: .45rem .8rem;
            border: 0;
            border-radius: 6px;
            color: var(--m-action-primary-text, #ffffff);
            background: var(--m-action-primary-bg, #d9822b);
            font-weight: 700;
        }
        .status {
            color: var(--m-status-success-text, #166534);
        }
        .errors {
            color: var(--m-status-danger-text, #991b1b);
        }
        dl {
            display: grid;
            grid-template-columns: max-content 1fr;
            gap: .5rem 1rem;
            margin: 0;
        }
        dt { font-weight: 700; }
        dd { margin: 0; overflow-wrap: anywhere; }
    </style>
</head>
<body>
    <main>
        <h1>Meridian Node Setup</h1>
        <p class="lede">
            This first-run page configures this Meridian server/node. Browsers,
            Electron, and mobile apps discover client settings from the server/API
            URL and later trusted-device setup.
        </p>

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

        @if ($node)
            <section class="panel" aria-labelledby="configured-node">
                <h2 id="configured-node">Configured Node</h2>
                <dl>
                    <dt>Name</dt>
                    <dd>{{ $node->node_name }}</dd>
                    <dt>Role</dt>
                    <dd>{{ $node->node_role }}</dd>
                    <dt>Central URL</dt>
                    <dd>{{ $node->central_node_url ?? 'Not set' }}</dd>
                    <dt>Public key</dt>
                    <dd>{{ $node->public_key }}</dd>
                </dl>
                <p class="help">
                    This first-run setup is complete. Ongoing node name, role,
                    and central URL edits are managed in Orchid God Mode under
                    Node Configuration.
                </p>
            </section>
        @else
            <form method="POST" action="{{ route('setup.store') }}">
                @csrf

                <label for="node_name">
                    Node name
                    <input
                        id="node_name"
                        name="node_name"
                        value="{{ old('node_name') }}"
                        required
                        autocomplete="off"
                        placeholder="juplaya.2027.onsite"
                    >
                </label>

                <label for="node_role">
                    Node role
                    <select id="node_role" name="node_role" required>
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected(old('node_role', 'development') === $role)>
                                {{ ucfirst($role) }}
                            </option>
                        @endforeach
                    </select>
                    <span class="help">
                        Use Development for local testing. Standalone, Central,
                        and Onsite are event-mode roles and require this
                        server's configured application URL to use HTTPS.
                    </span>
                </label>

                <label for="central_node_url">
                    Central node URL
                    <input
                        id="central_node_url"
                        name="central_node_url"
                        type="url"
                        value="{{ old('central_node_url') }}"
                        placeholder="https://central.example.org"
                    >
                </label>

                <button type="submit">Create node</button>
            </form>
        @endif
    </main>
</body>
</html>
