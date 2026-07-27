<div class="bg-white rounded shadow-sm p-4">
    @if ($node)
        <dl class="row mb-4">
            <dt class="col-sm-3">Node name</dt>
            <dd class="col-sm-9">{{ $node->node_name }}</dd>
            <dt class="col-sm-3">Node role</dt>
            <dd class="col-sm-9">{{ $node->node_role }}</dd>
            <dt class="col-sm-3">Central URL</dt>
            <dd class="col-sm-9">{{ $node->central_node_url ?? 'Not set' }}</dd>
        </dl>
    @else
        <p>No active node.</p>
    @endif

    @if ($node)
        <h4 class="h5">Central pairing</h4>
        <dl class="row mb-4">
            <dt class="col-sm-3">Pairing status</dt>
            <dd class="col-sm-9">{{ $pairing['status_label'] }}</dd>
            <dt class="col-sm-3">Central node</dt>
            <dd class="col-sm-9">{{ $pairing['central_node_name'] ?? 'Not paired' }}</dd>
            <dt class="col-sm-3">Paired at</dt>
            <dd class="col-sm-9">{{ $pairing['paired_at'] ?? 'Never' }}</dd>
        </dl>

        @if ($issuedPairingToken)
            <div class="alert alert-warning">
                <p class="mb-1"><strong>One-time pairing token</strong></p>
                <p class="mb-1"><code>{{ $issuedPairingToken }}</code></p>
                <p class="mb-0">Copy this token to the pairing node now. It is shown once and cannot be recovered.</p>
            </div>
        @endif

        @if ($node->isCentral())
            <p class="mb-4">
                Unused pairing tokens: <strong>{{ $pairingTokens->count() }}</strong>.
                Token values are stored hashed and are never displayed again.
            </p>
        @endif
    @endif

    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th scope="col">Key</th>
                    <th scope="col">Value</th>
                    <th scope="col">Source</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($configValues as $configValue)
                    <tr>
                        <th scope="row">{{ $configValue['key'] }}</th>
                        <td>{{ $configValue['display_value'] }}</td>
                        <td>{{ $configValue['source_label'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
