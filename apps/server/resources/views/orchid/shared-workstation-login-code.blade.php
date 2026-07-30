{{--
    The one render a generated shared-workstation login code exists in
    (technical spec 13.2; data/API 12.4). It is flashed for this request only,
    stored as a keyed hash, and cannot be recovered from this screen or the
    database afterwards. Codes are deliberately not printable or exportable as
    event prep sheets, so this block is the whole of how a code is handed over.
--}}
@if ($generatedCode)
    <div class="bg-white rounded shadow-sm p-4">
        <div class="alert alert-warning mb-0">
            <p class="mb-1"><strong>Login code for {{ $generatedCodeUser }}</strong></p>
            <p class="mb-2"><code>{{ $generatedCode }}</code></p>
            <p class="mb-1">
                Typed at <strong>{{ $generatedCodeWorkstation }}</strong>. Valid until
                {{ $generatedCodeExpiresAt ?? 'its six-week expiry' }}.
            </p>
            <p class="mb-0">
                Read it to them now. It is shown once, is stored only as a hash, and
                cannot be recovered. Do not print or export it.
            </p>
        </div>
    </div>
@endif
