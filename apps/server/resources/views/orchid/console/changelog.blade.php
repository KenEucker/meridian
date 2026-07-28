{{--
    Meridian releases, from the changelog data file packaged with this
    deployment (GOD-018 through GOD-021). Everything below renders without
    network access. No entry is filtered by conventional-commit type or change
    category (GOD-020).
--}}
<div class="bg-white rounded shadow-sm p-4">
    @if (! $packaged && count($releases) === 0)
        <div class="alert alert-warning mb-0" role="status">
            <strong>No changelog is packaged with this build.</strong>
            The release build step generates it. To generate one locally, run
            <code>corepack pnpm run changelog:generate</code>.
        </div>
    @else
        <p class="text-muted">
            Running build <strong>{{ $build_version }}</strong>.
            {{ $refresh['status_label'] }}.
            @if ($refresh['last_success_at'])
                Last successful refresh {{ $refresh['last_success_at'] }}.
            @else
                No successful refresh recorded on this node.
            @endif
            @if ($refresh['reason'])
                <span class="d-block">{{ $refresh['reason'] }}</span>
            @endif
        </p>

        @foreach ($releases as $release)
            <h3 class="h5 mt-4">Version {{ $release['version'] }}</h3>

            <ul class="list-unstyled">
                @foreach ($release['entries'] as $entry)
                    <li class="mb-3">
                        <div>
                            <strong>{{ $entry['title'] }}</strong>
                            <span class="text-muted">#{{ $entry['number'] }}</span>
                        </div>
                        <div class="text-muted small">
                            {{ $entry['author'] ?? 'unknown' }} — merged {{ $entry['merged_at'] ?? 'unknown' }}
                        </div>
                        @if (! empty($entry['body']))
                            <p class="mb-0 mt-1" style="white-space: pre-wrap;">{{ $entry['body'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endforeach
    @endif
</div>
