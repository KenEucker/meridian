# QA-NODE-01: First-Run Node Setup

## Purpose

Verify that a fresh Meridian server can create its first node, generate node keys, show node configuration source state in the admin surface, and pair an on-site node with a central node using a one-time pairing token.

## Requirements covered

- Technical spec section 7: Node Model
- Technical spec section 7.3: Node configuration and central pairing token
- Technical spec section 7.4: Node setup
- Technical spec section 22.4: God mode and config
- Technical spec section 26.2: Production/event safeguards
- Data/API spec section 13.1: `nodes`
- Data/API spec section 13.2: `node_config_values`
- Data/API spec section 13.4: `node_pairing_tokens`
- Data/API spec section 13.5: Node pairing endpoint
- UI implementation contract section 12.9: Orchid / God Mode Admin Screens

## Environment

- Development server environment
- Fresh or reset database with no active `nodes` rows
- Orchid admin route available at `/admin`
- For the pairing steps: two Meridian installs, one configured `central` and one
  configured `onsite` or `standalone`, each reachable from the other

## Personas

- Setup operator
- God mode administrator

## Setup data

- No organization or event setup is required.
- Use a node name such as `qa.2027.onsite`.
- Use the `development` role for local HTTP testing. Use `onsite`, `central`, or
  `standalone` only when `APP_URL` is HTTPS and required event-mode services are
  available.
- Optional central URL: `https://central.example.org`.
- For the pairing steps: name the central install `qa.central` and the on-site
  install `qa.2027.onsite`, and use the central install's URL as the on-site
  install's central node URL.

## Steps

1. Open `/setup`.
2. Enter the node name.
3. Select the node role.
4. Enter the optional central node URL.
5. Submit the setup form.
6. Refresh `/setup`.
7. Sign in as an Orchid user with the `platform.node.config` permission.
8. Open `/admin/node-config`.
9. Change the node name, role, or central node URL in Node Configuration and save.

Central pairing (two installs):

10. On the central install, open `/admin/node-config` and select **Create
    pairing token**.
11. Copy the displayed one-time token, then reload the page.
12. On the on-site install, open `/admin/node-config`, enter the central node
    URL and the copied token under Central pairing, and select **Pair with
    central**.
13. Reload `/admin/node-config` on both installs.
14. On the on-site install, select **Pair with central** again with the same
    token.
15. On the on-site install, change the central node URL under Node settings to a
    different URL and save, then reload `/admin/node-config`.
16. On the central install, select **Create pairing token** again, then select
    **Revoke unused tokens** and confirm.
17. On the on-site install, attempt to pair with the token from step 16.

## Expected results

- `/setup` shows the first-run form before setup.
- Submitting valid setup values creates one active node and shows the configured node state.
- Refreshing `/setup` does not show a second setup form.
- The configured node shows the selected name, role, optional central URL, and a public key.
- `/admin/node-config` shows node configuration rows.
- `/admin/node-config` lets a permitted God mode administrator edit the active
  node name, role, and central node URL after first-run setup.
- Setup-created values show `database override` as the source.
- Values that are not configured show `runtime/default` or `file config` as appropriate.
- Private key material is not displayed directly.
- Only a central node offers **Create pairing token**; only an on-site or
  standalone node offers **Pair with central**. A development node offers
  neither and shows pairing status `Not applicable for this node role`.
- The one-time token is displayed once with a warning that it cannot be
  recovered, and reloading the page no longer shows it.
- The issued token is displayed alongside an explanation that it joins a second
  Meridian server install rather than a device or Kiosk workstation, and
  step-by-step instructions naming the on-site install as where it is redeemed.
- **Revoke unused tokens** appears only on a central node that has at least one
  unused token, asks for confirmation, and reports how many were revoked.
- A revoked token is refused when it is presented for pairing, and already-used
  tokens are left untouched by revocation.
- After pairing, the on-site install shows pairing status `Paired with central`,
  the central node name, and a paired-at timestamp, and the central install
  lists the on-site node as a paired node.
- Reusing the same token from the same on-site install succeeds again without
  creating a second paired node record; using it from a different node is
  refused with a single-use explanation.
- After the central node URL is changed on the on-site install, pairing status
  reads `Pairing recheck required` until pairing is run again.

## Evidence to capture

- Screenshot of `/setup` before submission.
- Screenshot of `/setup` after submission.
- Screenshot of `/admin/node-config` showing config sources with private key hidden.
- Screenshot of `/admin/node-config` after editing an allowed node setting.
- Screenshot of the one-time pairing token on the central install.
- Screenshot of `/admin/node-config` on the on-site install showing
  `Paired with central`.
- Screenshot of `/admin/node-config` on the on-site install showing
  `Pairing recheck required` after the central node URL changes.

## Failure notes

- If `/setup` creates more than one active node, stop testing and file a blocking setup issue.
- If private key material is visible in `/admin/node-config`, stop testing and file a blocking security issue.
- If the Orchid screen is not permission-gated, stop testing and file a blocking God-mode issue.
- If an event-mode role saves while `APP_URL` is plain HTTP, stop testing and
  file a blocking event-mode safeguard issue.
- If a pairing token is displayed again after the first render, or is readable
  in the database, stop testing and file a blocking security issue.
- If a used pairing token pairs a second, different node, stop testing and file
  a blocking pairing issue.
- If pairing succeeds over plain HTTP while the node is in event mode, stop
  testing and file a blocking event-mode safeguard issue.
