import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import { configureMeridianApi } from "@/api/meridianApi";
import { followSessionBranding } from "@/branding/brandingContext";
import {
  brandingState,
  MERIDIAN_PROFILE,
  resetToMeridian,
  type BrandingProfilePayload,
} from "@/branding/brandingProfile";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  localFieldSessionDocument,
  LOCAL_FIELD_ORGANIZATION_ID,
  LOCAL_FIELD_OTHER_EVENT_ID,
  LOCAL_FIELD_OTHER_ORGANIZATION_ID,
  switchableLocalFieldContext,
} from "@/session/localFieldSession";

/*
 * Branding follows the session's organization (M16.7; CLIENT-011, CLIENT-014).
 *
 * Which organization the client is in is the session's answer, so branding
 * cannot be resolved from a build-time identifier and cannot be left behind by
 * a switch. Wiring it as a watch means booting from cache, the node's first
 * answer, and a context switch are all one rule.
 */

function profileFor(organizationId: string, name: string): BrandingProfilePayload {
  return {
    ...MERIDIAN_PROFILE,
    organization_id: organizationId,
    display_name: name,
    is_branded: true,
  };
}

const requested: string[] = [];

function respondWithProfiles() {
  return vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input);
    const organizationId = url.split("/organizations/")[1]?.split("/")[0] ?? "";

    requested.push(organizationId);

    return new Response(
      JSON.stringify(
        profileFor(
          organizationId,
          organizationId === LOCAL_FIELD_OTHER_ORGANIZATION_ID
            ? "Cascadia Collective"
            : "Idaho Burners",
        ),
      ),
      { status: 200, headers: { "content-type": "application/json" } },
    );
  });
}

let stopFollowing: (() => void) | null = null;

beforeEach(() => {
  requested.length = 0;
  window.localStorage.clear();
  clearClientSession();
  resetToMeridian();
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
  vi.stubGlobal("fetch", respondWithProfiles());
});

afterEach(() => {
  stopFollowing?.();
  stopFollowing = null;
  configureMeridianApi(null);
  clearClientSession();
  resetToMeridian();
  window.localStorage.clear();
  vi.unstubAllGlobals();
});

describe("branding following the session context", () => {
  it("resolves the organization the session context names", async () => {
    installClientSession(localFieldSessionDocument(), "network");
    stopFollowing = followSessionBranding();
    await vi.waitFor(() =>
      expect(brandingState.profile.display_name).toBe("Idaho Burners"),
    );

    expect(requested).toEqual([LOCAL_FIELD_ORGANIZATION_ID]);
  });

  it("re-resolves when a switch lands in another organization", async () => {
    // CLIENT-014: branding is one of the four things a switch re-resolves, and
    // it follows the organization rather than being re-resolved by hand.
    installClientSession(localFieldSessionDocument(), "network");
    stopFollowing = followSessionBranding();
    await vi.waitFor(() =>
      expect(brandingState.profile.display_name).toBe("Idaho Burners"),
    );

    const switchable = switchableLocalFieldContext();
    installClientSession(
      localFieldSessionDocument({
        ...switchable,
        context: {
          ...switchable.context,
          organization_id: LOCAL_FIELD_OTHER_ORGANIZATION_ID,
          event_id: LOCAL_FIELD_OTHER_EVENT_ID,
        },
      }),
      "network",
    );

    await vi.waitFor(() =>
      expect(brandingState.profile.display_name).toBe("Cascadia Collective"),
    );
    expect(requested).toEqual([
      LOCAL_FIELD_ORGANIZATION_ID,
      LOCAL_FIELD_OTHER_ORGANIZATION_ID,
    ]);
  });

  it("does not re-resolve when the event changes inside one organization", async () => {
    // The organization is what branding is keyed on, so an event switch that
    // stays put costs no request and no repaint.
    installClientSession(
      localFieldSessionDocument(switchableLocalFieldContext()),
      "network",
    );
    stopFollowing = followSessionBranding();
    await vi.waitFor(() => expect(requested).toHaveLength(1));

    const switchable = switchableLocalFieldContext();
    installClientSession(
      localFieldSessionDocument({
        ...switchable,
        context: { ...switchable.context, event_id: LOCAL_FIELD_OTHER_EVENT_ID },
      }),
      "network",
    );
    await nextTick();

    expect(requested).toEqual([LOCAL_FIELD_ORGANIZATION_ID]);
  });

  it("returns to Meridian's own identity when the session goes", async () => {
    // Losing the organization is losing the right to display it (BRAND-003).
    installClientSession(localFieldSessionDocument(), "network");
    stopFollowing = followSessionBranding();
    await vi.waitFor(() =>
      expect(brandingState.profile.display_name).toBe("Idaho Burners"),
    );

    clearClientSession();
    await nextTick();

    expect(brandingState.profile.display_name).toBe("Meridian");
    expect(brandingState.profile.is_branded).toBe(false);
  });

  it("keeps Meridian's identity when there was never a session to lose", async () => {
    stopFollowing = followSessionBranding();
    await nextTick();

    expect(requested).toEqual([]);
    expect(brandingState.profile.display_name).toBe("Meridian");
  });
});
