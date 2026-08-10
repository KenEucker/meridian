import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { flushPromises } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  kioskLandingRedirect,
  redirectWhenSignedOut,
  requiresSignIn,
  routes,
} from "@/router";
import {
  clearClientSession,
  installClientSession,
  refreshClientSession,
} from "@/session/clientSession";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";

/*
 * Sending a client that holds nothing to sign in (M16.11; AUTH-018, AUTH-030;
 * CLIENT-007).
 *
 * Not the security boundary — the node refuses a credential-less request
 * whatever the client rendered (technical spec 11A.2). What this decides is
 * whether somebody is left looking at a surface that can only be empty.
 */

function signedOut(): void {
  configureMeridianApi({ baseUrl: "http://node.test", bearerToken: null });
  clearClientSession();
}

beforeEach(signedOut);

afterEach(() => {
  configureMeridianApi(null);
  clearClientSession();
});

describe("the kiosk landing", () => {
  /*
   * A Kiosk opening at `/` used to reach `RootView`, which — holding no
   * personal credential — offered the marketing page and then the email
   * magic-link login. Wrong twice over: the machine cannot complete that login
   * (it holds a workstation session key, not a bearer token), and completing it
   * would put a personal device session on a machine strangers stand in front
   * of, which AUTH-030 exists to prevent.
   */
  it("sends the personal sign-in surfaces to the kiosk's own front door", () => {
    expect(kioskLandingRedirect("home", "kiosk")).toEqual({ name: "kiosk.home" });
    expect(kioskLandingRedirect("login", "kiosk")).toEqual({ name: "kiosk.home" });
    expect(kioskLandingRedirect("auth.code.entry", "kiosk")).toEqual({
      name: "kiosk.home",
    });
  });

  it("leaves the kiosk's own surfaces alone", () => {
    expect(kioskLandingRedirect("kiosk.workstation-login", "kiosk")).toBeNull();
    expect(kioskLandingRedirect("kiosk.setup", "kiosk")).toBeNull();
    expect(kioskLandingRedirect("kiosk.home", "kiosk")).toBeNull();
  });

  it("changes nothing in field and admin, where personal sign-in is the point", () => {
    expect(kioskLandingRedirect("home", "field")).toBeNull();
    expect(kioskLandingRedirect("login", "field")).toBeNull();
    expect(kioskLandingRedirect("login", "admin")).toBeNull();
  });
});

describe("requiring sign-in", () => {
  it("sends a client with no credential and no session to sign in", () => {
    expect(requiresSignIn("staff.field-reports.index")).toBe(true);
    expect(requiresSignIn("events.departments.overview")).toBe(true);
  });

  it("leaves the sign-in surfaces reachable", () => {
    expect(requiresSignIn("login")).toBe(false);
    expect(requiresSignIn("auth.code.entry")).toBe(false);
  });

  /*
   * A shared workstation holds a session key rather than a token and signs in by
   * typed code on its own screen (AUTH-030). Sending it to the personal sign-in
   * screen would send it somewhere it cannot use.
   */
  it("leaves every Kiosk surface alone", () => {
    expect(requiresSignIn("kiosk.home")).toBe(false);
    expect(requiresSignIn("kiosk.workstation-login")).toBe(false);
    expect(requiresSignIn("kiosk.safe-timeout")).toBe(false);
  });

  it("leaves not-found alone, which nobody was denied", () => {
    expect(requiresSignIn("not-found")).toBe(false);
  });

  it("lets a client holding a token through", () => {
    configureMeridianApi({
      baseUrl: "http://node.test",
      bearerToken: "device-token",
    });

    expect(requiresSignIn("staff.field-reports.index")).toBe(false);
  });

  /*
   * CLIENT-007: a device booted from its durable session is an offline device
   * mid-event. Bouncing it to a login screen it cannot complete is the failure
   * the cache exists to prevent.
   */
  it("lets a client working from a cached session through", () => {
    installClientSession(fixtureSessionDocument(), "cache");

    expect(requiresSignIn("staff.field-reports.index")).toBe(false);
  });

  /*
   * The first navigation happens while the boot resolution is still in flight.
   * "Holds nothing" then describes how far the boot has got rather than what the
   * client has, and deciding on it would bounce every client that has not
   * finished starting — including a developer session that is about to install.
   */
  it("decides nothing while the first session resolution is in flight", async () => {
    // The node is left mid-answer on purpose: this is the state the very first
    // navigation happens in.
    let answer: (response: Response) => void = () => undefined;

    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Promise<Response>((resolve) => {
        answer = resolve;
      })),
    );

    const refresh = refreshClientSession();

    expect(requiresSignIn("staff.field-reports.index")).toBe(false);

    answer(
      new Response(JSON.stringify({ message: "Unauthenticated." }), {
        status: 401,
        headers: { "content-type": "application/json" },
      }),
    );
    await refresh;

    expect(requiresSignIn("staff.field-reports.index")).toBe(true);

    vi.unstubAllGlobals();
  });
});

/*
 * Losing a session is not a navigation, so the route guard never sees it. A
 * device whose token is revoked while somebody is reading a surface has to be
 * moved off it (AUTH-023), not left there until the next click.
 */
describe("when a session is lost while somebody is standing on a surface", () => {
  it("moves the client to sign in", async () => {
    const router = createRouter({ history: createMemoryHistory(), routes });
    const stop = redirectWhenSignedOut(router);

    installClientSession(fixtureSessionDocument(), "network");

    await router.push({ name: "staff.field-reports.index" });
    expect(router.currentRoute.value.name).toBe("staff.field-reports.index");

    clearClientSession();
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("login");

    stop();
  });

  it("leaves a client that still holds a credential where it is", async () => {
    const router = createRouter({ history: createMemoryHistory(), routes });
    const stop = redirectWhenSignedOut(router);

    configureMeridianApi({
      baseUrl: "http://node.test",
      bearerToken: "device-token",
    });
    installClientSession(fixtureSessionDocument(), "network");

    await router.push({ name: "staff.field-reports.index" });

    clearClientSession();
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("staff.field-reports.index");

    stop();
  });
});
