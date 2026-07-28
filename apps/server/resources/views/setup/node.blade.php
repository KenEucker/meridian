<!DOCTYPE html>
<html lang="en">
<head>
    @include('meridian.surface-head')
    <title>Meridian Node Setup</title>
</head>
<body>
    <main>
        @include('meridian.brand', ['context' => __('Node setup')])

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

        @include('meridian.footer')
    </main>
</body>
</html>
