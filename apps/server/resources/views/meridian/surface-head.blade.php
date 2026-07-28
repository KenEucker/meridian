{{--
    Head content for Meridian's standalone server-rendered surfaces (M15C.4,
    M15C.6; GOD-031, GOD-038).

    Login, magic-link, logout, and node first-run setup render outside both the
    Vue product shell and the God Mode console, so each owns its own <head>.
    They share this partial so the favicon, the token stylesheet, and the
    surface stylesheet cannot drift apart between them.

    These surfaces load Meridian's tokens and nothing else. They never load an
    organization branding stylesheet: a person signing in may not belong to an
    organization yet, and a node being set up has none (BRAND-003).
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#475157">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="stylesheet" href="{{ asset('css/meridian-tokens.css') }}">
<link rel="stylesheet" href="{{ asset('css/meridian-surface.css') }}">
