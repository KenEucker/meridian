{{--
    Catalog drift: what this deployment has stored versus what this build
    defines. Rendered as an explicit all-clear rather than as nothing, so an
    operator can tell the difference between "checked, agrees" and "the check
    did not run".
--}}
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h3 class="h5 mb-3">{{ __('Catalog drift') }}</h3>

    @if ($hasDrift)
        <p class="text-muted">
            {{ __('The stored catalog does not match what this build defines. Until this is resolved, this node enforces permissions differently from other nodes running the same build.') }}
        </p>

        <ul class="list-unstyled mb-0">
            @foreach ($drift as $entry)
                <li class="mb-3">
                    <span class="badge bg-danger me-1">{{ $entry['kind'] }}</span>
                    <code>{{ $entry['subject'] }}</code>
                    <div class="text-muted small">{{ $entry['detail'] }}</div>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mb-0">
            {{ __('The stored catalog matches what this build defines. Every role, capability, and role-to-capability mapping agrees.') }}
        </p>
    @endif
</div>
