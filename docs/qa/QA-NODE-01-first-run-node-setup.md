# QA-NODE-01: First-Run Node Setup

## Purpose

Verify that a fresh Meridian server can create its first node, generate node keys, and show node configuration source state in the admin surface.

## Requirements covered

- Technical spec section 7: Node Model
- Technical spec section 22.4: God mode and config
- Technical spec section 26.2: Production/event safeguards
- Data/API spec section 13.1: `nodes`
- Data/API spec section 13.2: `node_config_values`
- UI implementation contract section 12.9: Orchid / God Mode Admin Screens

## Environment

- Development server environment
- Fresh or reset database with no active `nodes` rows
- Orchid admin route available at `/admin`

## Personas

- Setup operator
- God mode administrator

## Setup data

- No organization or event setup is required.
- Use a node name such as `qa.2027.onsite`.
- Use the `onsite` role for the first pass.
- Optional central URL: `https://central.example.org`.

## Steps

1. Open `/setup`.
2. Enter the node name.
3. Select the node role.
4. Enter the optional central node URL.
5. Submit the setup form.
6. Refresh `/setup`.
7. Sign in as an Orchid user with the `platform.node.config` permission.
8. Open `/admin/node-config`.

## Expected results

- `/setup` shows the first-run form before setup.
- Submitting valid setup values creates one active node and shows the configured node state.
- Refreshing `/setup` does not show a second setup form.
- The configured node shows the selected name, role, optional central URL, and a public key.
- `/admin/node-config` shows node configuration rows.
- Setup-created values show `database override` as the source.
- Values that are not configured show `runtime/default` or `file config` as appropriate.
- Private key material is not displayed directly.

## Evidence to capture

- Screenshot of `/setup` before submission.
- Screenshot of `/setup` after submission.
- Screenshot of `/admin/node-config` showing config sources with private key hidden.

## Failure notes

- If `/setup` creates more than one active node, stop testing and file a blocking setup issue.
- If private key material is visible in `/admin/node-config`, stop testing and file a blocking security issue.
- If the Orchid screen is not permission-gated, stop testing and file a blocking God-mode issue.
