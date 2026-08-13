import type { CapacitorConfig } from "@capacitor/cli";

const config: CapacitorConfig = {
  appId: "org.meridian.field",
  appName: "Meridian Field",
  webDir: "../client/dist/field",
  android: {
    /*
     * The packaged app is served from `https://localhost`, and an on-site node
     * answers plain HTTP at its home.arpa name — a name no public authority
     * will certify (technical spec 8.5). A request from a secure origin to an
     * insecure one is blockable mixed content, which the WebView drops before
     * it reaches the network: the phone shows an unreachable node while the
     * node's own logs stay empty, because nothing was ever sent.
     *
     * Narrower than it reads. What may actually leave the device in the clear
     * is decided by the Android network security configuration, which permits
     * cleartext to home.arpa and nothing else, so this lifts the WebView's
     * blanket refusal without widening that allowance.
     */
    allowMixedContent: true,
  },
};

export default config;
