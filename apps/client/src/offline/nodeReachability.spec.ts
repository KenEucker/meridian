import { afterEach, describe, expect, it } from "vitest";

import { clearNodeUrl, setNodeUrl } from "@/app/nodeConnection";
import {
  nodeReachability,
  recordNodeAnswered,
  recordNodeUnreachable,
  resetNodeReachability,
} from "@/offline/nodeReachability";

afterEach(() => {
  resetNodeReachability();
  clearNodeUrl();
  window.localStorage.clear();
});

describe("node reachability", () => {
  it("knows nothing before this device has spoken to its node", () => {
    // Not "reachable" and not "unreachable". A client that has made no request
    // has no evidence either way, and the shell's Unknown step exists so that
    // moment is not spent claiming one.
    expect(nodeReachability.value).toBe("unknown");
  });

  it("records an answer and a silence", () => {
    recordNodeAnswered();
    expect(nodeReachability.value).toBe("reachable");

    recordNodeUnreachable();
    expect(nodeReachability.value).toBe("unreachable");
  });

  /*
   * An observation belongs to the node it was made against.
   *
   * The case is a technician correcting a node address on an on-site device:
   * the old address was unreachable, which is why they are correcting it, and
   * carrying that verdict onto the new one would report a failure nobody has
   * tested for. It runs the other way too — a node that answered says nothing
   * about the machine at the address that replaced it.
   */
  it("forgets what it observed when the device is pointed at another node", () => {
    setNodeUrl("https://onsite.example.org");
    recordNodeUnreachable();
    expect(nodeReachability.value).toBe("unreachable");

    setNodeUrl("https://onsite-corrected.example.org");
    expect(nodeReachability.value).toBe("unknown");

    recordNodeAnswered();
    expect(nodeReachability.value).toBe("reachable");
  });

  it("stands by an observation while the node is unchanged", () => {
    setNodeUrl("https://onsite.example.org");
    recordNodeAnswered();

    setNodeUrl("https://onsite.example.org");

    expect(nodeReachability.value).toBe("reachable");
  });
});
