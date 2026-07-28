{{--
    Current branding assets, rendered as images (M15A.5, M15A.6, M15A.7).

    A file input alone gives an operator no way to tell whether the upload they
    just did worked, or what is stored now. The URL in a help string does not
    solve that: it is the picture that answers the question.

    An empty slot shows the generated lettermark that renders in its place
    (BRAND-005, BRAND-010) rather than nothing, so "no logo" is visibly a state
    rather than a broken image.
--}}
<div class="row">
    @foreach ($branding_slots ?? [] as $slot)
        <div class="col-md-4 mb-3">
            <div class="bg-white rounded shadow-sm p-3 h-100">
                <p class="small text-muted mb-2">{{ $slot['label'] }}</p>

                <div
                    class="d-flex align-items-center justify-content-center rounded mb-2"
                    style="height: 7rem; background: #f6f1e8; overflow: hidden;"
                >
                    @if ($slot['url'])
                        <img
                            src="{{ $slot['url'] }}"
                            alt="{{ $slot['label'] }}"
                            style="max-width: 100%; max-height: 100%; object-fit: contain;"
                        >
                    @else
                        <span
                            class="d-inline-flex align-items-center justify-content-center rounded"
                            style="width: 4rem; height: 4rem; background: #475157; color: #fffcf6; font-weight: 800; letter-spacing: .02em;"
                        >{{ $slot['lettermark'] }}</span>
                    @endif
                </div>

                @if ($slot['url'])
                    <a href="{{ $slot['url'] }}" target="_blank" rel="noopener" class="small">{{ __('Open full size') }}</a>
                @else
                    <span class="small text-muted">{{ __('No asset stored; the lettermark above renders instead.') }}</span>
                @endif
            </div>
        </div>
    @endforeach
</div>
