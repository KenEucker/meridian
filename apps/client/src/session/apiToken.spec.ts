import { afterEach, beforeEach, describe, expect, it } from "vitest";

import { configureMeridianApi, meridianApiConfig } from "@/api/meridianApi";
import {
  API_TOKEN_STORAGE_KEY,
  apiBearerToken,
  clearApiToken,
  heldApiToken,
  holdsApiToken,
  resetApiTokenForTests,
  storeApiToken,
} from "@/session/apiToken";

/*
 * The credential this client holds (M16.11; AUTH-018, AUTH-024).
 *
 * The token used to come from the build environment, which is what made every
 * install carry the same one. What is asserted here is the shape of what
 * replaced it: one token, issued to a person, durable across restarts, gone when
 * it expires, and never read from anywhere but this module.
 */

const USER = {
  id: "user-1",
  name: "Dana Reyes",
  email: "dana@example.test",
};

beforeEach(() => {
  window.localStorage.removeItem(API_TOKEN_STORAGE_KEY);
  resetApiTokenForTests();
  configureMeridianApi(null);
});

afterEach(() => {
  window.localStorage.removeItem(API_TOKEN_STORAGE_KEY);
  resetApiTokenForTests();
  configureMeridianApi(null);
});

describe("the held API token", () => {
  it("holds nothing until a token is stored", () => {
    expect(holdsApiToken()).toBe(false);
    expect(apiBearerToken()).toBeNull();
    expect(heldApiToken()).toBeNull();
  });

  it("keeps an issued token and reports who it belongs to", () => {
    storeApiToken({
      token: "mrdn_at_abc",
      user: USER,
      deviceId: "device-1",
      expiresAt: "2027-06-01T12:00:00+00:00",
    });

    expect(apiBearerToken(new Date("2027-05-01T00:00:00Z"))).toBe("mrdn_at_abc");
    expect(heldApiToken(new Date("2027-05-01T00:00:00Z"))).toEqual({
      user: USER,
      deviceId: "device-1",
      expiresAt: "2027-06-01T12:00:00+00:00",
    });
  });

  /*
   * The durable part. A staff member reopening Meridian Field on their phone
   * has not signed out, and asking them to sign in again — at an event, without
   * coverage — is exactly what this cannot do.
   */
  it("survives a restart", () => {
    storeApiToken({ token: "mrdn_at_abc", user: USER });

    resetApiTokenForTests();

    expect(apiBearerToken()).toBe("mrdn_at_abc");
  });

  it("is the bearer token every request carries", () => {
    storeApiToken({ token: "mrdn_at_abc", user: USER });

    expect(meridianApiConfig().bearerToken).toBe("mrdn_at_abc");
  });

  /*
   * Expiry is the node's (AUTH-024) and the client respects the stamp it was
   * given rather than sending a token it knows is dead and reporting the refusal
   * as a sign-in that ended.
   */
  it("drops a token whose expiry has passed", () => {
    storeApiToken({
      token: "mrdn_at_abc",
      user: USER,
      expiresAt: "2027-06-01T12:00:00+00:00",
    });

    expect(apiBearerToken(new Date("2027-06-01T12:00:01Z"))).toBeNull();
    expect(heldApiToken(new Date("2027-06-01T12:00:01Z"))).toBeNull();
    expect(window.localStorage.getItem(API_TOKEN_STORAGE_KEY)).toBeNull();
  });

  it("keeps a token the node gave no expiry for", () => {
    storeApiToken({ token: "mrdn_at_abc", user: USER });

    expect(apiBearerToken(new Date("2099-01-01T00:00:00Z"))).toBe("mrdn_at_abc");
  });

  it("forgets the token in memory and on disk when it is cleared", () => {
    storeApiToken({ token: "mrdn_at_abc", user: USER });

    clearApiToken();

    expect(apiBearerToken()).toBeNull();
    expect(window.localStorage.getItem(API_TOKEN_STORAGE_KEY)).toBeNull();

    resetApiTokenForTests();

    expect(apiBearerToken()).toBeNull();
  });

  it("holds nothing from a stored entry it cannot read", () => {
    window.localStorage.setItem(API_TOKEN_STORAGE_KEY, "{ not json");
    resetApiTokenForTests();

    expect(apiBearerToken()).toBeNull();
  });
});
