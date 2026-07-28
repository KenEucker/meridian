# God Mode Console Override Inventory

Milestone 15C restyles the God Mode console to Meridian's visual identity.
GOD-037 requires that restyling be applied through supported framework
configuration and template extension points wherever those exist, so a
framework upgrade does not force the identity to be reapplied by hand.

This document is the inventory that requirement asks for. It records every
extension point Meridian uses, and every place a vendor view had to be
overridden along with why.

`ConsoleUpgradeSafetyTest` enforces it: if a vendor view is ever published into
`apps/server/resources/views/vendor/platform/`, the test fails until that file
appears in the [Vendor view overrides](#vendor-view-overrides) table below.

## Vendor view overrides

**None.**

No view from `orchid/platform` has been published into
`apps/server/resources/views/vendor/platform/`. The entire console identity is
applied from outside the framework's own templates and stylesheets.

If that ever changes, add a row here stating the file, what forced the
override, and what would let it be removed:

| Vendor view | Why it had to be overridden | What would remove the need |
|---|---|---|

## Supported extension points in use

| Extension point | Where | What Meridian puts there |
|---|---|---|
| `platform.resource.stylesheets` | `apps/server/config/platform.php` | `/css/meridian-tokens.css` then `/css/meridian-console.css`. The framework links these after its own stylesheet, which is what lets the bridge override framework defaults without patching them. |
| `platform.template.header` | `apps/server/config/platform.php` | `meridian.console-header`, which pushes the Meridian favicon and the `noindex`/`notranslate`/`theme-color` meta into the console `<head>` and renders the brand lockup. |
| `platform.template.footer` | `apps/server/config/platform.php` | `meridian.footer`, which states Meridian's license, its 2026-to-present copyright range, and the Meridian build version. |
| `Dashboard::registerResource('scripts', …)` | `App\Orchid\PlatformProvider` | `js/meridian-admin.js`, the console's own field-scripting behavior. Unrelated to visual identity. |
| `Dashboard::renderMenu()` menu definition | `App\Orchid\PlatformProvider::menu()` | Meridian's navigation, including the Documentation and Changelog console pages and the Meridian build version badge (M15B.11). |

## How the identity is applied without overriding views

Three properties of the framework's own chrome make this possible, and each is
worth stating because losing one is what would force a vendor override later.

**The framework's chrome is Bootstrap, and Bootstrap 5.3 is themed through CSS
custom properties.** `meridian-console.css` re-declares those custom properties
in terms of the shared `--m-*` tokens rather than restating colors. Surface,
foreground, border, focus, action, radius, shadow, and type scale all resolve
from `@meridian/ui-tokens`.

**The framework's stylesheet is linked before the dashboard resource
stylesheets.** Ordering, not specificity, is what lets the bridge win. Two
categories still need care, and both are the source of most of the bridge's
awkward-looking rules:

- Framework declarations carrying `!important` have to be re-declared at the
  same weight: `bg-white` and `layout` (which the framework paints white in one
  shared rule, so overriding only `bg-white` leaves every screen heading white),
  `bg-light`, `text-muted`, the `bg-dark` navigation, the `shadow` set, and
  every `btn-*` variant — the framework paints its buttons with hard-coded
  colors rather than through Bootstrap's `--bs-btn-*` variables.
- Framework declarations written at a longer selector than Bootstrap's own have
  to be matched selector for selector: table cells are styled at
  `.table thead tr th` and `.table tbody tr td`, which outrank
  `.table > :not(caption) > * > *`.

Both are worth re-checking after a framework upgrade, because either one fails
silently — as an unstyled region rather than as an error.

**The framework exposes its header and footer partials as configuration.** The
brand lockup, the favicon, and the license/copyright/version statement are the
only markup Meridian needs to place inside the console chrome, and both of the
places they belong are configurable.

One consequence is worth recording. The framework hard-codes
`data-bs-theme="dark"` on the navigation column regardless of the user's theme,
and that attribute is on markup Meridian does not own. Rather than override the
view to remove it, `meridian-console.css` styles the navigation as one
primary-colored surface: its background is `--m-action-primary-bg` and its
foreground is `--m-action-primary-text`. That pair is the platform primary and
the label color the branding token resolver pairs with it, it is identical in
Meridian's light and dark themes, and it is already contrast-validated as an
action label — so the navigation is legible in both themes without the
application asserting anything about the framework's theme attribute.

## What to check after a framework upgrade

1. `ConsoleUpgradeSafetyTest` and `ConsoleVisualIdentityTest` pass.
2. `ConsoleContrastTest` passes — an upgrade that changes which custom
   properties the chrome reads would show up as an unstyled region rather than
   as a failure, so run [`QA-GOD-02`](../qa/QA-GOD-02-console-visual-identity.md)
   as well.
3. No new file has appeared under `apps/server/resources/views/vendor/platform/`.
4. The framework has not reintroduced its own license, copyright range, or
   version anywhere the Meridian footer does not replace (GOD-032).
