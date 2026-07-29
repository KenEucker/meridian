@php
    use App\Services\SystemConfig\ResolvedConfigValue;

    $sourceBadge = function (string $source): string {
        return match ($source) {
            ResolvedConfigValue::SOURCE_DATABASE_OVERRIDE => 'bg-primary',
            ResolvedConfigValue::SOURCE_ENVIRONMENT => 'bg-success',
            ResolvedConfigValue::SOURCE_LARAVEL_DEFAULT => 'bg-secondary',
            ResolvedConfigValue::SOURCE_MISSING => 'bg-warning text-dark',
            ResolvedConfigValue::SOURCE_INVALID => 'bg-danger',
            default => 'bg-light text-dark border',
        };
    };
@endphp

<div class="bg-white rounded shadow-sm p-4 mb-3">
    @unless ($node)
        <div class="alert alert-warning mb-3">
            This install has no configured node yet. The catalogue and effective sources are shown,
            but database overrides cannot be stored until node setup completes.
        </div>
    @endunless

    <form method="GET" action="{{ route('platform.system.configuration') }}" class="row g-2 mb-4">
        <div class="col-md-4">
            <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control"
                   placeholder="Search name, label, section, config key">
        </div>
        <div class="col-md-2">
            <select name="section" class="form-select">
                <option value="">All sections</option>
                @foreach ($sections as $section)
                    <option value="{{ $section }}" @selected($filters['section'] === $section)>{{ $section }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <select name="source" class="form-select">
                <option value="">All sources</option>
                @foreach ($sources as $source)
                    <option value="{{ $source }}" @selected($filters['source'] === $source)>
                        {{ ResolvedConfigValue::labelForSource($source) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <select name="state" class="form-select">
                <option value="">All states</option>
                <option value="overridden" @selected($filters['state'] === 'overridden')>Has override</option>
                <option value="pending" @selected($filters['state'] === 'pending')>Override pending</option>
                <option value="secret" @selected($filters['state'] === 'secret')>Secret</option>
                <option value="editable" @selected($filters['state'] === 'editable')>Editable</option>
                <option value="locked" @selected($filters['state'] === 'locked')>Bootstrap-locked</option>
                <option value="invalid" @selected($filters['state'] === 'invalid')>Invalid</option>
                <option value="unmapped" @selected($filters['state'] === 'unmapped')>Unmapped</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-default w-100">Filter</button>
        </div>
    </form>

    <p class="text-muted small mb-3">
        Showing {{ count($values) }} of {{ $total }} catalogued variables. Precedence is
        <strong>database override → environment / .env → Laravel default</strong>. Secrets are never
        displayed; they can be replaced, disabled, or removed but not read back.
    </p>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
            <tr>
                <th>Variable</th>
                <th>Section</th>
                <th>Effective value</th>
                <th>Source</th>
                <th>Override</th>
                <th>Activation</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($values as $value)
                @php($entry = $value->entry)
                <tr>
                    <td>
                        <code>{{ $entry->name }}</code>
                        <div class="text-muted small">{{ $entry->label }}</div>
                        @if ($entry->configKeys !== [])
                            <div class="text-muted small">→ {{ implode(', ', $entry->configKeys) }}</div>
                        @endif
                    </td>
                    <td class="small">{{ $entry->section }}</td>
                    <td>
                        <code>{{ $value->displayValue }}</code>
                        @if ($entry->secret)
                            <span class="badge bg-dark ms-1">secret</span>
                        @endif
                        @if ($value->validationError)
                            <div class="text-danger small">{{ $value->validationError }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $sourceBadge($value->source) }}">{{ $value->sourceLabel() }}</span>
                    </td>
                    <td class="small">
                        @if ($value->override)
                            {{ $value->override->is_active ? 'Active' : 'Disabled' }}
                            @if ($value->overridePending)
                                <span class="badge bg-warning text-dark">pending activation</span>
                            @endif
                            <div class="text-muted">
                                {{ $value->override->updated_at?->toDayDateTimeString() }}
                                @if ($value->override->updatedByUser)
                                    by {{ $value->override->updatedByUser->name }}
                                @endif
                            </div>
                        @else
                            <span class="text-muted">None</span>
                        @endif
                    </td>
                    <td class="small">
                        {{ $entry->activationLabel() }}
                        @if ($entry->required)
                            <span class="badge bg-info text-dark">required</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('platform.system.configuration.edit', ['variable' => $entry->name]) }}"
                           class="btn btn-sm btn-default">
                            @if ($entry->editable() && $canManage)
                                Edit
                            @else
                                Inspect
                            @endif
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No variables match the current filters.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
