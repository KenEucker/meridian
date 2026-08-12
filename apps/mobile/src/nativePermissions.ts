/**
 * The code-defined native permission inventory for Meridian Field (M19.21).
 *
 * Technical spec 26.4 commits the native projects so their permission
 * declarations are reviewable in a diff; this module is the review's anchor.
 * A native project may declare exactly the permissions listed here — each one
 * tied to the shipped surface that asks for it — and the config tests fail the
 * build on any drift in either direction: a permission declared in a native
 * project without an inventory entry is unexplained, and an inventory entry
 * without a declaration is a surface that would break in the installed app.
 *
 * Adding a permission therefore starts here, with the surface that needs it
 * and the reason it does, and the native declarations follow. The M18.61 QR
 * decoding choice is what put the camera on this list: scanning uses the
 * WebView's own `getUserMedia` and `BarcodeDetector` rather than a native
 * barcode plugin, so the camera is the only native capability the scan path
 * touches, and a denied camera reaches the typed short-code fallback.
 */

export interface NativePermissionEntry {
  /** The exact identifier the native project declares. */
  readonly permission: string;
  /** The shipped surface that asks for it. */
  readonly surface: string;
  /** Why that surface needs it. */
  readonly reason: string;
}

/** `<uses-permission>` entries the Android manifest must declare, exactly. */
export const ANDROID_PERMISSIONS: readonly NativePermissionEntry[] = [
  {
    permission: "android.permission.INTERNET",
    surface: "every Meridian Field surface",
    reason:
      "The packaged Field client talks to a Meridian node over the network " +
      "(technical spec 3.3); without INTERNET the WebView cannot reach any node.",
  },
  {
    permission: "android.permission.CAMERA",
    surface: "staff.workstation-code (M18.61)",
    reason:
      "Scanning a workstation sign-in QR uses getUserMedia in the WebView, " +
      "decoded by the browser's BarcodeDetector; Android only grants the " +
      "WebView camera access when the app declares CAMERA. A denied camera " +
      "reaches the typed short-code fallback.",
  },
];

/** Usage-description keys the iOS Info.plist must declare, exactly. */
export const IOS_USAGE_DESCRIPTIONS: readonly NativePermissionEntry[] = [
  {
    permission: "NSCameraUsageDescription",
    surface: "staff.workstation-code (M18.61)",
    reason:
      "Scanning a workstation sign-in QR uses getUserMedia in the WKWebView, " +
      "which iOS refuses without a camera usage description. A denied camera " +
      "reaches the typed short-code fallback.",
  },
];
