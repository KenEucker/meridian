{{--
    How this node stores audit history, above the per-organization settings.

    Partitioning is a property of the node's database rather than of any
    organization, so it is reported here rather than being repeated on each
    organization's screen. An explicit "not partitioned" beats silence: an
    operator wondering why a limit is the only thing bounding the table should
    be able to see the answer rather than infer it.
--}}
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h3 class="h5 mb-3">{{ __('Audit storage on this node') }}</h3>

    @if (! $partitioning_supported)
        <p class="mb-0">
            {{ __('This node runs a database without declarative partitioning, so the audit table is a single table. Per-organization limits are what bound its growth here.') }}
        </p>
    @elseif ($partitioning_active)
        <p class="mb-3">
            {{ __('The audit table is partitioned by month. Time-ranged reads touch only the months they cover, and a month can later be detached and archived without a delete running against live rows.') }}
        </p>

        <p class="mb-2">
            <span class="badge bg-success">{{ __('Partitioned') }}</span>
            <span class="text-muted small ms-1">
                {{ trans_choice(':count partition|:count partitions', count($partitions), ['count' => count($partitions)]) }}
            </span>
        </p>

        <ul class="list-unstyled mb-0 small text-muted">
            @foreach ($partitions as $partition)
                <li><code>{{ $partition['name'] }}</code> — {{ $partition['bounds'] }}</li>
            @endforeach
        </ul>
    @else
        <p class="mb-0">
            {{ __('This node supports partitioning but the audit table is not partitioned. It was either switched off in configuration before the table was built, or the migration has not run. Per-organization limits still apply.') }}
        </p>
    @endif
</div>
