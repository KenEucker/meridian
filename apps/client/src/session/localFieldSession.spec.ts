import { afterEach, describe, expect, it } from "vitest";

import {
  clearClientSession,
  clientSessionState,
  installClientSession,
} from "@/session/clientSession";
import {
  installLocalFieldSessionFromEnv,
  localFieldSessionDocument,
} from "@/session/localFieldSession";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";

/*
 * When the development session may stand in for a real one (M16.6, M16.11;
 * CLIENT-024).
 *
 * It exists so a developer running against a seeded node sees a populated shell
 * without signing in. What it must never do is paper over a session the client
 * actually has, or one the node has just taken away — the second is how a
 * revoked developer would be shown a live-looking session by the client that was
 * just refused.
 */

const enabled = { VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION: "true" };

afterEach(() => {
  clearClientSession();
});

describe("the development session", () => {
  it("is installed when the flag is on and the client holds nothing", () => {
    expect(installLocalFieldSessionFromEnv({ env: enabled })).toBe(true);
    expect(clientSessionState.document?.user.name).toBe(
      localFieldSessionDocument().user.name,
    );
  });

  it("is not installed without the flag", () => {
    expect(
      installLocalFieldSessionFromEnv({
        env: { VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION: undefined },
      }),
    ).toBe(false);
    expect(clientSessionState.document).toBeNull();
  });

  it("never replaces a session the client already holds", () => {
    const held = fixtureSessionDocument();

    installClientSession(held, "cache");

    expect(installLocalFieldSessionFromEnv({ env: enabled })).toBe(false);
    expect(clientSessionState.document?.user.id).toBe(held.user.id);
  });

  /*
   * AUTH-023: a revoked token or a revoked device arrives as an unauthenticated
   * refresh, and the client drops its session and its credential. Signed out is
   * then the true state, and the shell has to show it.
   */
  it("stays out of the way when a credential this client held was refused", () => {
    expect(
      installLocalFieldSessionFromEnv({ env: enabled, credentialRefused: true }),
    ).toBe(false);
    expect(clientSessionState.document).toBeNull();
  });

  /*
   * The case the fixture exists for. A client that has never signed in is
   * refused by the node too, and that refusal is not somebody being signed out.
   */
  it("still stands in for a client that never signed in", () => {
    expect(
      installLocalFieldSessionFromEnv({ env: enabled, credentialRefused: false }),
    ).toBe(true);
    expect(clientSessionState.document).not.toBeNull();
  });
});
