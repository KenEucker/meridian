@php
    use App\Services\SystemConfig\CatalogEntry;

    $entry = $value->entry;
@endphp

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <dl class="row mb-0">
        <dt class="col-sm-3">Variable</dt>
        <dd class="col-sm-9"><code>{{ $entry->name }}</code> — {{ $entry->label }}</dd>

        <dt class="col-sm-3">Section</dt>
        <dd class="col-sm-9">{{ $entry->section }}</dd>

        @if ($entry->description !== '')
            <dt class="col-sm-3">Description</dt>
            <dd class="col-sm-9">{{ $entry->description }}</dd>
        @endif

        <dt class="col-sm-3">Laravel config key(s)</dt>
        <dd class="col-sm-9">
            @if ($entry->configKeys !== [])
                <code>{{ implode(', ', $entry->configKeys) }}</code>
            @else
                <span class="text-muted">Unmapped — this variable is read-only here.</span>
            @endif
        </dd>

        <dt class="col-sm-3">Type</dt>
        <dd class="col-sm-9">
            {{ $entry->type }}@if ($entry->type === CatalogEntry::TYPE_ENUM): {{ implode(' | ', $entry->enumValues) }}@endif
            @if ($entry->secret) <span class="badge bg-dark">secret</span> @endif
            @if ($entry->required) <span class="badge bg-info text-dark">required</span> @endif
        </dd>

        <dt class="col-sm-3">Effective value</dt>
        <dd class="col-sm-9"><code>{{ $value->displayValue }}</code></dd>

        <dt class="col-sm-3">Effective source</dt>
        <dd class="col-sm-9">{{ $value->sourceLabel() }}</dd>

        <dt class="col-sm-3">Activation</dt>
        <dd class="col-sm-9">{{ $entry->activationLabel() }}</dd>

        @if ($value->override)
            <dt class="col-sm-3">Stored override</dt>
            <dd class="col-sm-9">
                <code>{{ $value->overrideDisplayValue }}</code>
                — {{ $value->override->is_active ? 'active' : 'disabled' }}
                @if ($value->overridePending)
                    <span class="badge bg-warning text-dark">pending activation</span>
                @endif
            </dd>
        @endif

        @if ($value->validationError)
            <dt class="col-sm-3">Validation</dt>
            <dd class="col-sm-9 text-danger">{{ $value->validationError }}</dd>
        @endif
    </dl>
</div>

@if ($entry->bootstrapLocked)
    <div class="alert alert-warning">
        <strong>Bootstrap-locked.</strong> This value is needed before database overrides can load
        — the database connection, the application key, or node identity itself — so it can only be
        changed in the node's environment file or deployment configuration, followed by the restart
        that layer requires.
    </div>
@elseif ($entry->managedLabel !== null)
    <div class="alert alert-info">
        <strong>Managed by {{ $entry->managedLabel }}.</strong> Meridian writes this value itself;
        edit it on that surface rather than here so its side effects (re-validation, pairing state)
        actually run.
    </div>
@elseif (! $entry->mapped())
    <div class="alert alert-info">
        <strong>Read-only.</strong> This variable has no declared Laravel configuration mapping, so a
        database override could not truthfully take effect. It is catalogued for visibility only.
    </div>
@elseif ($entry->editable())
    @if ($entry->secret)
        <div class="alert alert-warning">
            <strong>Secret value.</strong> The stored value is encrypted and cannot be viewed — not
            here, not in exports, not in the audit log. Entering a value replaces it. A change
            reason is required.
        </div>
    @endif

    @if ($entry->required || $entry->activation() !== CatalogEntry::ACTIVATION_REQUEST)
        <div class="alert alert-warning">
            <strong>Read before saving.</strong>
            @if ($entry->required)
                This setting is required for the node to operate; an invalid override is skipped at
                boot and the environment value keeps winning, but a valid-but-wrong one can take the
                node down at its next activation point.
            @endif
            Activation: {{ $entry->activationLabel() }}. Saving does not restart anything for you —
            the table above will show the override as pending until every affected process has
            restarted.
        </div>
    @endif

    @if ($canManage && (! $entry->secret || $canManageSecret))
        <div class="bg-white rounded shadow-sm p-4 mb-3">
            <h4 class="h5 mb-3">{{ $value->override ? 'Change override' : 'Add override' }}</h4>

            <div class="mb-3">
                <label class="form-label" for="override-value">
                    {{ $entry->secret ? 'New secret value (replaces the stored one)' : 'Override value' }}
                </label>
                <input type="{{ $entry->secret ? 'password' : 'text' }}"
                       id="override-value"
                       name="override[value]"
                       value="{{ old('override.value') }}"
                       autocomplete="off"
                       form="post-form"
                       class="form-control @error('override.value') is-invalid @enderror"
                       placeholder="{{ $entry->type === CatalogEntry::TYPE_ENUM ? implode(' | ', $entry->enumValues) : $entry->type }}">
                @error('override.value')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-1">
                <label class="form-label" for="override-reason">
                    Change reason {{ $entry->secret || $entry->required ? '(required)' : '(recommended)' }}
                </label>
                <textarea id="override-reason" name="override[reason]" rows="2" form="post-form"
                          class="form-control">{{ old('override.reason') }}</textarea>
            </div>
        </div>
    @elseif (! $canManage)
        <div class="alert alert-secondary">
            You can inspect this variable but you do not hold the manage permission needed to change it.
        </div>
    @else
        <div class="alert alert-secondary">
            Changing this secret requires the secret-configuration permission, which you do not hold.
        </div>
    @endif
@endif

@if ($canSeeAudit)
    <div class="bg-white rounded shadow-sm p-4">
        <h4 class="h5 mb-3">Audit history</h4>
        @if ($auditEvents === [])
            <p class="text-muted mb-0">No override changes have been recorded for this variable on this node.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>Action</th>
                        <th>Actor</th>
                        <th>Reason</th>
                        <th>Values (redacted for secrets)</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($auditEvents as $auditEvent)
                        <tr>
                            <td class="small">{{ $auditEvent->created_at?->toDayDateTimeString() }}</td>
                            <td class="small"><code>{{ $auditEvent->action }}</code></td>
                            <td class="small">{{ $auditEvent->actorUser?->name ?? 'System' }}</td>
                            <td class="small">{{ $auditEvent->reason ?? '—' }}</td>
                            <td class="small">
                                @if ($auditEvent->before_json)
                                    <div>Before: <code>{{ json_encode($auditEvent->before_json['value'] ?? null) }}</code></div>
                                @endif
                                @if ($auditEvent->after_json)
                                    <div>After: <code>{{ json_encode($auditEvent->after_json['value'] ?? null) }}</code></div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
