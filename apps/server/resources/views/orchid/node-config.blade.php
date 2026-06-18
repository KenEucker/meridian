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
