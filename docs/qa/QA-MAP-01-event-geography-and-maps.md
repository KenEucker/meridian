# QA-MAP-01: Event Geography and Maps

> **Status: waiting on Milestone 14.** This script was added by M18.36 because
> the plan has referenced it since Milestone 14 was specified, and a referenced
> script that does not exist reads as coverage that was never planned. None of
> the surfaces it names are built yet. It is written from the Milestone 14
> acceptance criteria and QA gate, becomes runnable as M14.1 through M14.12
> land, and M14.13 owns correcting it to what actually ships before it is run
> as a gate.

## Purpose

Verify the MVP Event Geography and Maps feature end to end: the Placement
department designation, map and camp/location authoring before the operations
window, publishing, permitted visibility, the kiosk dashboard map, optional
IMS references, offline sync with sensitive-data exclusion, and
operations-window locking with an organizer override.

The feature is intentionally simple. Half of this script exists to prove what
was *not* built: no GIS editor, no dropped-pin workflow, no map fields on Field
Reports, no camps in the command palette, and no volunteer correction path.

## Requirements covered

- `PLACE-001` through `PLACE-012`: the Placement department designation, its
  event-assignment validation, and map authority derived from it.
- `MAP-001` through `MAP-019`: event maps enabled by default, draft/published/
  archived lifecycle, import, operations-window locking, the map view surface,
  and the command palette exclusion.
- `CAMP-001` through `CAMP-007` and `LOC-001` through `LOC-003`: camps as
  name-plus-location records and lightweight map locations.
- `MAPIMS-001` through `MAPIMS-006`: optional incident camp/location reference.
- `MAPOPS-001`, `MAPOPS-002`, `MAPFR-001`: optional shift meeting and
  deployment references; Field Reports gain no location field.
- `MAPKIOSK-001`: the kiosk dashboard map.
- `MAPSYNC-001` through `MAPSYNC-003`: offline map packages, permitted
  camp/location data, and sensitive-layer exclusion.
- `MAPCORR-001`: the organizer/admin override for locked map data; volunteers
  cannot submit corrections.
- Technical spec section 21A; UI screen surface section 13A; UI implementation
  contract section 12.11.

## Environment

- Development server environment with a migrated and seeded database
  (`php artisan migrate:fresh --seed`).
- Shared Meridian client (`apps/client`), Admin and Field artifacts.
- Kiosk artifact against a pinned shared workstation for section E.
- One device that can be taken offline for section G.

## Personas

From the seeded operational scenario:

- Olive Organizer — organizer for Northwood Collective.
- Dana Departmentlead — department lead for Rangers. For this script Rangers is
  designated the Placement department, so Dana holds map-management authority.
- Gabe Gatekeeper — Gate department lead, holding no Placement standing.
- Vera Staff — regular staff holding no roles.
- Omar ICOperator — IC operator for the event's Incident Command department.
- Gwen Godmode — console access for the Orchid half of designation setup.

All sign in with the documented development password.

## Setup data

- The seeded scenario (Northwood Collective, Emberfall 2026, Rangers/Gate/DPW
  departments) with the event's operations window **not yet started**.
- A simple placement map image or prepared package to import.
- One shared workstation pinned to Emberfall 2026 for section E.

## Steps

### A. Placement designation

1. As Olive Organizer, open event administration for Emberfall 2026 and confirm
   maps are enabled without anyone having turned them on.
2. Designate Rangers as the event's Placement department. Confirm the selector
   offers only departments assigned to the event, and that a department of
   another organization or one not participating is refused.
3. Confirm the designation can be cleared back to zero Placement departments,
   then set it to Rangers again.
4. As Gwen Godmode, confirm the same designation is visible from the console
   with the organization default beside the event value.

### B. Authoring before the operations window

5. As Dana Departmentlead, create a draft placement map for Emberfall 2026 and
   import the prepared asset. Confirm lightweight metadata only — no GIS
   editing, no geocoding.
6. Create two camp records with name and location, and two map locations with
   lightweight types. Confirm a camp record asks for nothing beyond name and
   location.
7. As Gabe Gatekeeper, confirm the map authoring surfaces are absent: a lead
   outside the Placement department holds no map-management authority.
8. As Vera Staff, confirm no authoring surface and no draft map is reachable.

### C. Publishing and visibility

9. As Dana, publish the map. Confirm the publish is audited and the map's state
   reads published.
10. As Omar ICOperator, open the Event Map screen and confirm the published map
    renders with the map selector, scoped search, layer toggles, and the
    camp/location detail drawer.
11. As Vera Staff, confirm camp names are not offered: a volunteer without map
    permission does not read the camp list (`CAMP` visibility is permitted
    standing, not event-wide).
12. Search for a camp from the map surface's own scoped search and confirm it
    matches there. Then open the global command palette and search for the same
    camp name. Confirm the palette matches nothing (`MAP-018`; QA-NAV-01
    section D is the standing check of the same rule).

### D. IMS and operational references

13. As Omar, create an incident and reference one of the seeded camps from the
    optional selector. Confirm the free-text location field is still present
    and still free.
14. Create a second incident with no camp or map location at all. Confirm
    nothing requires one.
15. Open the Field Report create form and confirm it remains a single text body
    with no map selector and no location field (`MAPFR-001`).
16. As Dana, set an optional meeting location on a shift and an optional map
    location on a deployment, and confirm both stay optional.

### E. Kiosk

17. On the pinned kiosk, confirm the dashboard includes the published map by
    default for the pinned context.
18. Unpublish (archive) the map as Dana, and confirm the kiosk dashboard drops
    the map rather than rendering a broken tile. Re-publish it.

### F. Locking at the operations window

19. Move the event into its operations window (adjust the seeded window or use
    the console).
20. As Dana, attempt to edit the published map's geometry and a camp record.
    Confirm both are refused with the lock stated.
21. As Olive Organizer, use the override path with a reason. Confirm the edit
    applies and the override and its reason are audited.
22. As Vera Staff, confirm there is no correction-submission path anywhere on
    the map surface.

### G. Offline

23. On a permitted device signed in as Omar, load the map, then take the device
    offline. Confirm the published map package and permitted camp/location data
    still render read-only, unchanged by the lock.
24. On a device signed in as Vera, confirm the synced data contains no
    sensitive camp/location layers — what the server withholds online is absent
    offline, not cached and hidden.

## Expected results

- Maps are on by default; the Placement designation accepts only departments
  assigned to the event and may be empty.
- Placement leads hold map-management authority before the operations window;
  other leads and staff hold none.
- Publishing is what makes a map visible to permitted lead/IC/kiosk users, and
  camp names never reach unpermitted volunteers, online or offline.
- Incidents may reference a camp or location and never require one; Field
  Reports stay single-body.
- The kiosk dashboard carries the published map by default and drops it when
  archived.
- The operations window locks map geometry and camp/location records with only
  the audited organizer/admin override path through.
- No dropped-pin workflow, no palette entry, and no volunteer correction path
  exist.

## Evidence to capture

- Screenshots of the designation selector refusing a non-participating
  department.
- Screenshots of the map surface as Dana, Omar, and Vera.
- The audit rows for publish and for the locked-data override with its reason.
- A screenshot of the kiosk dashboard with and without the published map.
- A screenshot of the offline map render and of the palette matching nothing
  for a camp name.

## Failure notes

Record the persona, department context, the map/camp record touched, and the
exact refusal or rendering observed. A camp name visible to Vera anywhere —
list, search, palette, or offline cache — is a privacy finding, not a cosmetic
one. An editable locked record without an audited override, or a Field Report
form that has grown a location field, is a scope finding against the
intentionally-simple boundary this feature is required to keep.
