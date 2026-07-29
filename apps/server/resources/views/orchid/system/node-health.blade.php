@php
    use App\Services\Diagnostics\DiagnosticCategory;
    use App\Services\Diagnostics\DiagnosticStatus;

    $statusBadge = function (string $status): string {
        return match ($status) {
            DiagnosticStatus::HEALTHY => 'bg-success',
            DiagnosticStatus::WARNING => 'bg-warning text-dark',
            DiagnosticStatus::CRITICAL => 'bg-danger',
            DiagnosticStatus::UNKNOWN => 'bg-secondary',
            default => 'bg-light text-dark border',
        };
    };
@endphp

<div class="bg-white rounded shadow-sm p-4">
    <p class="text-muted small mb-4">
        Latest sanitized health report per known node, delivered over the signed node report channel
        every ten minutes while a node can reach central. Reports carry statuses, versions, and
        counts — never configuration values, secrets, or operational data. An on-site node that is
        queuing operations while offline is doing its job; only a <em>stale</em> report from a node
        that should be reachable needs attention.
    </p>

    @forelse ($reports as $report)
        <div class="border rounded p-3 mb-3">
            <div class="d-flex align-items-center mb-2">
                <strong>{{ $report->node_name }}</strong>
                <span class="text-muted small ms-2">{{ $report->node_role }}</span>
                @if ($node && $report->node_id === $node->getKey())
                    <span class="badge bg-light text-dark border ms-2">this node</span>
                @endif
                <span class="badge {{ $statusBadge($report->overall_status) }} ms-2">
                    {{ DiagnosticStatus::label($report->overall_status) }}
                </span>
                @if ($report->isStale())
                    <span class="badge bg-warning text-dark ms-2">stale report</span>
                @endif
                <span class="text-muted small ms-auto">
                    v{{ $report->meridian_version }} —
                    reported {{ $report->generated_at?->toDayDateTimeString() }}
                </span>
            </div>

            <div class="mb-2">
                @foreach ($report->category_statuses_json ?? [] as $category => $status)
                    <span class="badge {{ $statusBadge($status) }} me-1">
                        {{ DiagnosticCategory::label($category) }}
                    </span>
                @endforeach
            </div>

            <dl class="row mb-0 small text-muted">
                @foreach ($report->summary_json ?? [] as $key => $item)
                    <dt class="col-sm-3 text-truncate">{{ $key }}</dt>
                    <dd class="col-sm-9 mb-0"><code>{{ $item ?? 'null' }}</code></dd>
                @endforeach
            </dl>

            @if (($report->warnings_json ?? []) !== [])
                <hr>
                <ul class="mb-0 small">
                    @foreach ($report->warnings_json as $warning)
                        <li>
                            <span class="badge {{ $statusBadge($warning['status'] ?? '') }} me-1">{{ $warning['status'] ?? '' }}</span>
                            <code>{{ $warning['key'] ?? '' }}</code> — {{ $warning['summary'] ?? '' }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p class="text-muted mb-0">
            No health reports yet. Reports appear after the first scheduled
            <code>meridian:health-report</code> run on this node, and as paired nodes deliver theirs.
        </p>
    @endforelse
</div>
