/**
 * electron-builder entry point for the Meridian Kiosk installers (M19.20).
 *
 * The real configuration lives in `src/packagingConfig.ts` — pure and unit
 * tested — and this file only loads its compiled form, so packaging requires
 * the same `compile` step the wrapper itself does. Run through the `package`
 * script, which compiles first.
 */

const { buildDesktopPackagingConfig } = require("./dist/packagingConfig.js");

module.exports = buildDesktopPackagingConfig(__dirname);
