{{--
    Meridian footer (M15C.5, M15C.6; GOD-028, GOD-032, GOD-033, GOD-037).

    Wired through `platform.template.footer` in config/platform.php for the God
    Mode console, and included directly by the standalone login, magic-link,
    logout, and node setup surfaces so they carry the same statement.

    Replacing the framework's footer removes its license statement, its
    2016-to-present copyright range, its version link, and its project links in
    one move — none of which describe Meridian.

    The license and the version are resolved from the repository's root
    manifest rather than written into this template, so the footer cannot drift
    from what Meridian is actually published as or built from (GOD-033).
--}}
@php
    // 2026 to present (GOD-032). "Present" resolves to the current year, and
    // collapses to a single year while that year is still 2026.
    $copyrightRange = date('Y') > '2026' ? '2026–'.date('Y') : '2026';
@endphp

<div class="meridian-footer user-select-none">
    <p>
        {{ __('Meridian is published under the :license license.', ['license' => config('meridian.license')]) }}
    </p>

    <p>
        &copy; {{ $copyrightRange }} {{ __('Meridian contributors') }}
    </p>

    <p class="meridian-footer__version">
        {{ __('Meridian version') }}: {{ config('meridian.version') }}
    </p>
</div>
