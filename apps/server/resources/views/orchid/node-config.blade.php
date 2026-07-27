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
                <p class="mb-2"><code>{{ $issuedPairingToken }}</code></p>
                <p class="mb-0">
                    Copy this token now. It is shown once, is stored only as a hash,
                    and cannot be recovered from this screen or the database.
                </p>
            </div>

            <div class="alert alert-info">
                <p class="mb-1"><strong>What this token is for</strong></p>
                <p>
                    This token joins a second Meridian <strong>server install</strong> to this
                    central node, so the two can sync event operations. It is not a device,
                    browser, or Kiosk credential. Trusted personal devices register their own
                    signing key during device trust setup, and Kiosk uses shared workstation
                    login codes; neither uses this token.
                </p>
                <p class="mb-1"><strong>How to use it</strong></p>
                <ol class="mb-1">
                    <li>Open Node Configuration on the <strong>on-site install</strong>, not this one.</li>
                    <li>Confirm that install's node role is <code>onsite</code> or <code>standalone</code>. A central node never shows the pairing form.</li>
                    <li>Under Central pairing, enter this node's URL and paste the token.</li>
                    <li>Select <strong>Pair with central</strong>.</li>
                </ol>
                <p class="mb-0">
                    The token pairs one node once. Re-running pairing from the same node is
                    safe, but a different node cannot reuse it.
                </p>
            </div>
        @endif

        @if ($node->isCentral())
            <p class="mb-4">
                Unused pairing tokens: <strong>{{ $pairingTokens->count() }}</strong>.
                Token values are stored hashed and are never displayed again. Tokens do not
                expire, so revoke any that were issued in error or lost.
            </p>
        @endif

        @if ($node->canPairWithCentral())
            <p class="mb-4">
                This node pairs with a central node by redeeming a one-time token created in
                Node Configuration on that central install. Pairing joins two Meridian server
                installs; devices and Kiosk workstations use their own trust flows.
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
