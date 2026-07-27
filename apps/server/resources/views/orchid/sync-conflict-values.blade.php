<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h4 class="h5">{{ __('Local and remote values') }}</h4>
    <p class="text-muted">
        {{ __('Conflicts show both local and remote values. Values are not edited here; the conflict resolver chooses which side to keep.') }}
    </p>

    <div class="row">
        <div class="col-md-6 mb-3">
            <h5 class="h6">{{ __('Local value') }}</h5>
            <pre class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap; word-break: break-word;">{{ $local_value_display }}</pre>
        </div>
        <div class="col-md-6 mb-3">
            <h5 class="h6">{{ __('Remote value') }}</h5>
            <pre class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap; word-break: break-word;">{{ $remote_value_display }}</pre>
        </div>
    </div>

    <h5 class="h6 mt-2">{{ __('Stored operation payload (diagnostic)') }}</h5>
    <p class="text-muted small">
        {{ __('payload_json is unsigned and diagnostic only. Appliers never apply it; conflict review may still show it.') }}
    </p>
    <pre class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap; word-break: break-word;">{{ $operation_payload_display }}</pre>
</div>
