{{--
    Meridian console header (M15C.3, M15C.4; GOD-030, GOD-031, GOD-037).

    Wired through `platform.template.header` in config/platform.php, which is
    the framework's supported hook for replacing its header partial. The
    framework renders this in two places — inside the navigation's brand link
    on every console screen, and above the card on its authentication surfaces
    — so replacing this one partial covers both without touching a vendor view.

    The framework's own partial pushed its favicon and robots/translate meta
    from here, so this one does the same; that is the only place in the
    console's <head> the framework exposes to the application.
--}}
@push('head')
    <meta name="robots" content="noindex"/>
    <meta name="google" content="notranslate">

    {{-- GOD-031: the Meridian favicon in place of the framework's. The `any`
         sizing and the `favicon` id match what the framework declared, so its
         asset is replaced rather than competing with a second icon link. --}}
    <link
        href="{{ asset('favicon.ico') }}"
        sizes="any"
        id="favicon"
        rel="icon"
    >

    <!-- For Safari on iOS: the console navigation color, not the framework's. -->
    <meta name="theme-color" content="#475157">
@endpush

@include('meridian.brand', ['context' => __('God Mode')])
