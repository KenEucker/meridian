/**
 * Generate the native launcher icons and splash screens from the Meridian
 * identity mark (M19.21).
 *
 * The one source is `assets/icon.png` — the Meridian identity mark, the same
 * 512x512 image `apps/kiosk/assets/icon.png` builds the desktop installer
 * icons from. Every Android mipmap, adaptive-icon layer, and splash drawable,
 * and every iOS app icon and splash image, is derived from it here and
 * committed, so the native projects stay reviewable in a diff and identical on
 * every machine that builds a release (technical spec 26.4).
 *
 * The generated files are committed rather than produced on each build; run
 * this script only when the identity mark or the surface color changes:
 *
 *     corepack pnpm --filter @meridian/mobile run assets:generate
 *
 * Backgrounds use the Meridian app surface token (`--m-surface-app`, light)
 * from packages/ui-tokens/tokens.css. The iOS marketing icon must be fully
 * opaque — App Store validation rejects an alpha channel — and the Android
 * adaptive-icon foreground keeps the mark inside the central safe zone that
 * launcher masks are guaranteed to preserve.
 */

import { mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import sharp from "sharp";

const mobileRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const mark = resolve(mobileRoot, "assets", "icon.png");

/** The Meridian app surface token (ui-tokens --m-surface-app, light). */
const SURFACE = "#f6f1e8";

/** Round-rect radius for legacy launcher icons, as a fraction of the edge. */
const LEGACY_RADIUS = 0.12;

/** How much of the canvas edge the mark spans, per asset kind. */
const MARK_SPAN = {
  legacy: 0.78,
  round: 0.66,
  // The adaptive safe zone is the central 66/108 of the canvas; 0.5 keeps the
  // whole mark inside it with margin for circular masks.
  foreground: 0.5,
  ios: 0.78,
  splash: 0.35,
};

async function markResized(edge) {
  return sharp(mark)
    .resize(edge, edge, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png()
    .toBuffer();
}

function roundedRect(edge, radius, fill) {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${edge}" height="${edge}"><rect width="${edge}" height="${edge}" rx="${radius}" ry="${radius}" fill="${fill}"/></svg>`;
  return Buffer.from(svg);
}

function circle(edge, fill) {
  const half = edge / 2;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${edge}" height="${edge}"><circle cx="${half}" cy="${half}" r="${half}" fill="${fill}"/></svg>`;
  return Buffer.from(svg);
}

async function writeComposite(background, markEdge, outPath) {
  await mkdir(dirname(outPath), { recursive: true });
  await sharp(background)
    .composite([{ input: await markResized(markEdge) }])
    .png({ compressionLevel: 9 })
    .toFile(outPath);
}

/** Legacy launcher icon: surface round-rect under the mark. */
async function legacyIcon(edge, outPath) {
  const background = roundedRect(edge, Math.round(edge * LEGACY_RADIUS), SURFACE);
  await writeComposite(background, Math.round(edge * MARK_SPAN.legacy), outPath);
}

/** Round launcher icon: surface circle under the mark. */
async function roundIcon(edge, outPath) {
  await writeComposite(circle(edge, SURFACE), Math.round(edge * MARK_SPAN.round), outPath);
}

/** Adaptive-icon foreground: transparent, mark held inside the safe zone. */
async function foregroundIcon(edge, outPath) {
  await mkdir(dirname(outPath), { recursive: true });
  await sharp({
    create: { width: edge, height: edge, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } },
  })
    .composite([{ input: await markResized(Math.round(edge * MARK_SPAN.foreground)) }])
    .png({ compressionLevel: 9 })
    .toFile(outPath);
}

/** Splash screen: the mark centered on the surface color. */
async function splash(width, height, outPath) {
  await mkdir(dirname(outPath), { recursive: true });
  const markEdge = Math.round(Math.min(width, height) * MARK_SPAN.splash);
  await sharp({
    create: { width, height, channels: 3, background: SURFACE },
  })
    .composite([{ input: await markResized(markEdge) }])
    .png({ compressionLevel: 9 })
    .toFile(outPath);
}

/** iOS marketing icon: opaque surface square under the mark, no alpha. */
async function iosIcon(edge, outPath) {
  await mkdir(dirname(outPath), { recursive: true });
  await sharp({
    create: { width: edge, height: edge, channels: 3, background: SURFACE },
  })
    .composite([{ input: await markResized(Math.round(edge * MARK_SPAN.ios)) }])
    .removeAlpha()
    .png({ compressionLevel: 9 })
    .toFile(outPath);
}

const res = (...parts) => resolve(mobileRoot, ...parts);

const ANDROID_RES = ["android", "app", "src", "main", "res"];
const ANDROID_DENSITIES = [
  ["mdpi", 48, 108],
  ["hdpi", 72, 162],
  ["xhdpi", 96, 216],
  ["xxhdpi", 144, 324],
  ["xxxhdpi", 192, 432],
];
const ANDROID_SPLASHES = [
  ["drawable", 480, 320],
  ["drawable-land-mdpi", 480, 320],
  ["drawable-land-hdpi", 800, 480],
  ["drawable-land-xhdpi", 1280, 720],
  ["drawable-land-xxhdpi", 1600, 960],
  ["drawable-land-xxxhdpi", 1920, 1280],
  ["drawable-port-mdpi", 320, 480],
  ["drawable-port-hdpi", 480, 800],
  ["drawable-port-xhdpi", 720, 1280],
  ["drawable-port-xxhdpi", 960, 1600],
  ["drawable-port-xxxhdpi", 1280, 1920],
];
const IOS_ASSETS = ["ios", "App", "App", "Assets.xcassets"];

for (const [density, iconEdge, foregroundEdge] of ANDROID_DENSITIES) {
  const dir = res(...ANDROID_RES, `mipmap-${density}`);
  await legacyIcon(iconEdge, resolve(dir, "ic_launcher.png"));
  await roundIcon(iconEdge, resolve(dir, "ic_launcher_round.png"));
  await foregroundIcon(foregroundEdge, resolve(dir, "ic_launcher_foreground.png"));
}

for (const [drawable, width, height] of ANDROID_SPLASHES) {
  await splash(width, height, res(...ANDROID_RES, drawable, "splash.png"));
}

await iosIcon(1024, res(...IOS_ASSETS, "AppIcon.appiconset", "AppIcon-512@2x.png"));

for (const name of ["splash-2732x2732.png", "splash-2732x2732-1.png", "splash-2732x2732-2.png"]) {
  await splash(2732, 2732, res(...IOS_ASSETS, "Splash.imageset", name));
}

console.log("Native launcher icons and splash screens regenerated from assets/icon.png.");
