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

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <div class="d-flex align-items-center mb-3">
        <span class="badge {{ $statusBadge($report->overallStatus()) }} fs-6 me-3">
            Overall: {{ DiagnosticStatus::label($report->overallStatus()) }}
        </span>
        <span class="text-muted small">
            Generated {{ $report->generatedAt->toDayDateTimeString() }}
            @if ($node)
                on {{ $node->node_name }} ({{ $node->node_role }})
            @endif
            — checks run when this page loads; use Refresh to run them again.
        </span>
    </div>

    <div class="mb-3">
        @foreach ($report->statusCounts() as $status => $count)
            @if ($count > 0)
                <span class="badge {{ $statusBadge($status) }} me-1">{{ DiagnosticStatus::label($status) }}: {{ $count }}</span>
            @endif
        @endforeach
    </div>

    <form method="GET" action="{{ route('platform.system.diagnostics') }}" class="row g-2">
        <div class="col-md-3">
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ DiagnosticStatus::label($status) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select name="category" class="form-select">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ DiagnosticCategory::label($category) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select name="required" class="form-select">
                <option value="">Required and optional</option>
                <option value="required" @selected($filters['required'] === 'required')>Required only</option>
                <option value="optional" @selected($filters['required'] === 'optional')>Optional only</option>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-default w-100">Filter</button>
        </div>
    </form>
</div>

@foreach ($checks as $check)
    <div class="bg-white rounded shadow-sm p-4 mb-3">
        <div class="d-flex align-items-center mb-2">
            <span class="badge {{ $statusBadge($check->result->status) }} me-2">{{ DiagnosticStatus::label($check->result->status) }}</span>
            <strong>{{ $check->label }}</strong>
            <span class="text-muted small ms-2">{{ DiagnosticCategory::label($check->category) }}</span>
            <span class="badge {{ $check->required ? 'bg-info text-dark' : 'bg-light text-dark border' }} ms-2">
                {{ $check->required ? 'required' : 'optional' }}
            </span>
            <span class="text-muted small ms-auto">
                {{ number_format($check->durationMs, 1) }} ms at {{ $check->checkedAt->format('H:i:s') }}
            </span>
        </div>

        <p class="mb-2">{{ $check->result->summary }}</p>

        @if ($check->result->recommendedAction)
            <p class="mb-2"><strong>Recommended:</strong> {{ $check->result->recommendedAction }}</p>
        @endif

        @if ($check->result->details !== [])
            <dl class="row mb-0 small text-muted">
                @foreach ($check->result->details as $key => $detail)
                    <dt class="col-sm-3 text-truncate">{{ $key }}</dt>
                    <dd class="col-sm-9 mb-0">
                        <code>{{ is_bool($detail) ? ($detail ? 'true' : 'false') : ($detail ?? 'null') }}</code>
                    </dd>
                @endforeach
            </dl>
        @endif
    </div>
@endforeach

@if ($checks === [])
    <div class="bg-white rounded shadow-sm p-4 text-center text-muted">
        No checks match the current filters.
    </div>
@endif
