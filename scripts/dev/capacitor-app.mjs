/**
 * What the committed Capacitor projects declare, for the scripts that run them.
 *
 * `capacitor.config.ts` is the identity source of truth the native projects
 * are generated and tested against (`apps/mobile/src/nativeProjects.ts`), so
 * the application identifier is read from it rather than repeated in each
 * runner, where a copy could disagree with what a build actually produced.
 */

/**
 * The application identifier to install and launch.
 *
 * @param {string} text Contents of `apps/mobile/capacitor.config.ts`.
 * @returns {string | null}
 */
export function appIdFromCapacitorConfig(text) {
  const match = /\bappId:\s*["']([^"']+)["']/.exec(text);
  return match ? match[1] : null;
}
