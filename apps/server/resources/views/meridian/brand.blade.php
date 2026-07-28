{{--
    The Meridian brand lockup (M15C.3, M15C.6; GOD-030, GOD-038).

    One lockup for every server-rendered surface: the God Mode console
    navigation, the console's authentication surface, and the standalone login,
    magic-link, logout, and node setup pages. They share this partial so they
    cannot drift into three slightly different Meridians.

    The lockup is the Meridian mark plus the product name set in the Meridian
    heading face, matching the product shell masthead. The name is text rather
    than the raster wordmark because it has to read on the console's
    primary-colored navigation as well as on light surfaces, and the wordmark
    asset is fixed dark ink.

    `$context` is the surface's own label under the product name. Collapsed
    console navigation hides the name block and shows the mark alone (GOD-030);
    it is hidden with a visually-hidden rule rather than removed, so the brand
    link keeps its accessible name.
--}}
@php($context = $context ?? null)

<span class="meridian-brand">
    <img
        class="meridian-brand__mark"
        src="{{ asset('img/brand/meridian-mark.png') }}"
        alt=""
        width="40"
        height="40"
        aria-hidden="true"
    >
    <span class="meridian-brand__name">
        <span class="meridian-brand__product">Meridian</span>
        @if ($context)
            <span class="meridian-brand__context">{{ $context }}</span>
        @endif
    </span>
</span>
