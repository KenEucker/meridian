<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import {
  holdsMeridianCredential,
  meridianApiConfig,
  meridianFetch,
} from "@/api/meridianApi";
import { apiLoginState } from "@/session/apiLogin";
import {
  authorFieldReportCatalog,
  fieldReportCatalogRevision,
  fieldReportPhotoRevision,
} from "@/field-reports/fieldReportRuntime";
import { resolveFieldSession } from "@/field-reports/fieldSession";
import { listPendingFieldReportPhotoRecords } from "@/field-reports/pendingFieldReportPhotos";
import SessionPermissionsNotice from "@/session/SessionPermissionsNotice.vue";
import {
  useStaffLinks,
  useWorkflowLinks,
} from "@/components/workflowLinks";
import { clientSessionState } from "@/session/clientSession";
import {
  HIDEABLE_PAGES,
  pageHidden,
  setPageHidden,
  type HideablePage,
} from "@/session/hiddenPages";
import {
  menuPageKeyFor,
  pageInMenu,
  setPageInMenu,
} from "@/session/menuPages";
import {
  resolveReadinessChecklist,
  summarizeReadiness,
} from "@/readiness/checklist";
import { useNodeConnectionStatus } from "@/offline/useConnectivity";
import { describeCommand } from "@/outbox/commandCatalog";
import {
  commandOutbox,
  commandOutboxRevision,
} from "@/outbox/commandOutboxRuntime";
import { CLIENT_VERSION } from "@/version";

interface ServerHealth {
  readonly status?: string | null;
  readonly environment?: string | null;
  readonly server_version?: string | null;
  readonly config_schema_version?: number | null;
  readonly timestamp?: string | null;
}

type ServerHealthState = "checking" | "reachable" | "unreachable";
type ThemeChoice = "light" | "dark";

const themeStorageKey = "meridian.ui.theme";

const nodeConnection = useNodeConnectionStatus();
const theme = ref<ThemeChoice>(readPreferredTheme());
const serverHealthState = ref<ServerHealthState>("checking");
const serverHealth = ref<ServerHealth | null>(null);
const serverHealthError = ref<string | null>(null);
const pendingPhotoCount = ref(0);
const failedPhotoCount = ref(0);
const uploadedLocalPhotoCount = ref(0);
const refreshing = ref(false);

const apiConfig = computed(() => meridianApiConfig());
const healthUrl = computed(() => `${apiConfig.value.baseUrl}/api/health`);
/*
 * Resolved here rather than passed from the readiness screen, and reactively
 * rather than once: signing in, pointing this device at a node, and switching
 * event all move the count, and every one of those can happen while this page is
 * open.
 */
const readiness = computed(() =>
  summarizeReadiness(resolveReadinessChecklist()),
);
/*
 * Whether there is a session to report permissions for at all. A device that
 * holds none is not stale — it has nothing — and the Permissions section says
 * that rather than rendering an empty heading.
 */
const sessionEstablished = computed(
  () => clientSessionState.document !== null,
);
const fieldSession = computed(() => resolveFieldSession());
const fieldSessionText = computed(() => {
  const session = fieldSession.value;
  if (!session) {
    return "Unavailable.";
  }

  const context = [session.departmentLabel, session.teamLabel].filter(
    (value): value is string => Boolean(value),
  );

  return context.length > 0
    ? `${session.eventLabel} / ${context.join(" / ")}`
    : session.eventLabel;
});
/*
 * Whether this device can send anything at all (M16.11).
 *
 * A signed-in device holds a bearer token; a Kiosk holds a workstation session
 * key instead (AUTH-030). Diagnostics reports the credential question the outbox
 * actually asks, so "why is nothing syncing" has the same answer on both.
 */
const hasCommandCredential = computed(() => {
  void apiConfig.value;

  return holdsMeridianCredential();
});
const signedInAs = computed(() => {
  const user = apiLoginState.user;

  return user === null ? null : user.name || user.email;
});
const submittedReportCount = computed(() => {
  void fieldReportCatalogRevision.value;
  return authorFieldReportCatalog.size;
});
/*
 * Device diagnostics is where the full command outbox is reported, accepted
 * commands included (M16.10; CLIENT-017; UI operating guide 17.4 — sync detail
 * belongs in advanced mode). The shell notice states held and refused work; this
 * is the whole queue, by state and by command.
 */
const outboxCounts = computed(() => {
  void commandOutboxRevision.value;

  return {
    queued: commandOutbox.byStatus("queued").length,
    sending: commandOutbox.byStatus("sending").length,
    accepted: commandOutbox.byStatus("accepted").length,
    rejected: commandOutbox.byStatus("rejected").length,
  };
});
const outboxText = computed(() => {
  const { queued, sending, accepted, rejected } = outboxCounts.value;

  if (queued + sending + accepted + rejected === 0) {
    return "Empty; no commands held on this device.";
  }

  return `${queued} queued; ${sending} sending; ${accepted} accepted; ${rejected} rejected.`;
});
const rejectedCommands = computed(() => {
  void commandOutboxRevision.value;

  return commandOutbox.byStatus("rejected").map((command) => ({
    key: command.idempotencyKey,
    label: describeCommand(command.commandType).label,
    detail: command.detail,
    reason: command.statusReason ?? "The node gave no reason.",
  }));
});
const pendingTextCount = computed(() => {
  void commandOutboxRevision.value;
  return commandOutbox.unsent("submit-field-report").length;
});
const serverHealthText = computed(() => {
  if (serverHealthState.value === "checking") {
    return "Checking server health endpoint...";
  }

  if (serverHealthState.value === "unreachable") {
    return serverHealthError.value ?? "Unreachable.";
  }

  const status = serverHealth.value?.status ?? "ok";
  const version = serverHealth.value?.server_version ?? "version unavailable";
  return `Reachable (${status}); server ${version}.`;
});
const fieldReportSyncText = computed(() => {
  const localPhotoWork = pendingPhotoCount.value + failedPhotoCount.value;

  if (!hasCommandCredential.value) {
    return "Blocked: this device is not signed in.";
  }

  if (!fieldSession.value) {
    return "Blocked: field session is unavailable.";
  }

  if (serverHealthState.value === "unreachable") {
    return "Blocked: local Meridian server health is unreachable.";
  }

  if (serverHealthState.value === "checking") {
    return "Checking server before evaluating command sync.";
  }

  if (pendingTextCount.value > 0 || localPhotoWork > 0) {
    return "Ready to retry pending Field Report sync.";
  }

  return "Ready; no pending Field Report work.";
});
const photoSyncText = computed(() => {
  if (
    pendingPhotoCount.value === 0 &&
    failedPhotoCount.value === 0 &&
    uploadedLocalPhotoCount.value === 0
  ) {
    return "No local Field Report photos.";
  }

  const parts = [];
  if (pendingPhotoCount.value > 0) {
    parts.push(`${pendingPhotoCount.value} pending`);
  }
  if (failedPhotoCount.value > 0) {
    parts.push(`${failedPhotoCount.value} failed`);
  }
  if (uploadedLocalPhotoCount.value > 0) {
    parts.push(`${uploadedLocalPhotoCount.value} uploaded locally`);
  }
  return parts.join(", ");
});
const lastServerTimestamp = computed(
  () => serverHealth.value?.timestamp ?? "Unavailable",
);
const serverEnvironment = computed(
  () => serverHealth.value?.environment ?? "Unavailable",
);
const configSchemaVersion = computed(() =>
  serverHealth.value?.config_schema_version === null ||
  serverHealth.value?.config_schema_version === undefined
    ? "Unavailable"
    : String(serverHealth.value.config_schema_version),
);

/*
 * The pages this reader has put away (M18.69).
 *
 * The setting is on the account rather than on the device, so it follows them
 * to the next machine they sign in on — which also means it needs the node, and
 * a refusal has to be said out loud rather than left as a control that quietly
 * snapped back.
 */
const hideablePages = HIDEABLE_PAGES;
const pageVisibilityBusy = ref<string | null>(null);
const pageVisibilityError = ref<string | null>(null);

function isPageShown(page: HideablePage): boolean {
  return !pageHidden(page.key);
}

async function setPageShown(page: HideablePage, shown: boolean): Promise<void> {
  if (pageVisibilityBusy.value !== null || isPageShown(page) === shown) {
    return;
  }

  pageVisibilityBusy.value = page.key;
  pageVisibilityError.value = null;

  try {
    await setPageHidden(page.key, !shown);
  } catch (error) {
    pageVisibilityError.value =
      error instanceof Error
        ? error.message
        : "The node did not accept the change.";
  } finally {
    pageVisibilityBusy.value = null;
  }
}

/*
 * What this reader keeps in their menus (M18.69).
 *
 * The rows are built from the reader's own menus rather than from a fixed list,
 * so somebody is only ever offered entries their session actually has, and each
 * row is named with the words the menu uses for it. A catalog of labels here
 * would be a second place for the same page to be called something, and the
 * first thing to drift.
 *
 * The two menus are listed as one set. The shell already merges them under the
 * threshold, so "which menu is this in" is a question with a moving answer, and
 * not one worth making a reader hold while they decide what they work out of.
 *
 * Deduplicated by page key. The dashboards are one page under four routes, and
 * a reader holding two of those standings should find one control rather than
 * the same control twice.
 */
const staffMenuPages = useStaffLinks();
const workflowMenuPages = useWorkflowLinks();

type MenuPageRow = {
  readonly key: string;
  readonly label: string;
  readonly description: string;
};

const menuPageRows = computed<MenuPageRow[]>(() => {
  const rows = new Map<string, MenuPageRow>();

  for (const link of [...staffMenuPages.value, ...workflowMenuPages.value]) {
    const key = menuPageKeyFor(link.to.name);

    if (key === null || rows.has(key)) {
      continue;
    }

    rows.set(key, {
      key,
      label: link.pageLabel ?? link.label,
      description: link.description ?? "",
    });
  }

  return [...rows.values()];
});

const menuPageBusy = ref<string | null>(null);
const menuPageError = ref<string | null>(null);

function isPageInMenu(key: string): boolean {
  return pageInMenu(key);
}

async function setInMenu(key: string, inMenu: boolean): Promise<void> {
  if (menuPageBusy.value !== null || pageInMenu(key) === inMenu) {
    return;
  }

  menuPageBusy.value = key;
  menuPageError.value = null;

  try {
    await setPageInMenu(key, inMenu);
  } catch (error) {
    menuPageError.value =
      error instanceof Error
        ? error.message
        : "The node did not accept the change.";
  } finally {
    menuPageBusy.value = null;
  }
}

function readPreferredTheme(): ThemeChoice {
  if (typeof window === "undefined") {
    return "dark";
  }

  try {
    const saved = window.localStorage.getItem(themeStorageKey);
    return saved === "light" || saved === "dark" ? saved : "dark";
  } catch {
    return "dark";
  }
}

function setTheme(value: ThemeChoice): void {
  theme.value = value;
}

function isServerHealth(value: unknown): value is ServerHealth {
  return typeof value === "object" && value !== null;
}

async function refreshDiagnostics(): Promise<void> {
  refreshing.value = true;
  serverHealthState.value = "checking";
  serverHealth.value = null;
  serverHealthError.value = null;

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 5000);

  try {
    const response = await meridianFetch("/api/health", {
      method: "GET",
      signal: controller.signal,
    });
    if (!response.ok) {
      throw new Error(`Health endpoint returned HTTP ${response.status}.`);
    }

    const body = (await response.json()) as unknown;
    if (!isServerHealth(body)) {
      throw new Error("Health endpoint returned an unexpected payload.");
    }

    serverHealth.value = body;
    serverHealthState.value = "reachable";
  } catch (error) {
    serverHealthState.value = "unreachable";
    serverHealthError.value =
      error instanceof Error
        ? error.message
        : "Unable to reach local Meridian server.";
  } finally {
    clearTimeout(timeout);

    try {
      void fieldReportPhotoRevision.value;
      const photoRecords = await listPendingFieldReportPhotoRecords();
      pendingPhotoCount.value = photoRecords.filter(
        (record) => record.syncStatus === "pending_upload",
      ).length;
      failedPhotoCount.value = photoRecords.filter(
        (record) => record.syncStatus === "failed",
      ).length;
      uploadedLocalPhotoCount.value = photoRecords.filter(
        (record) => record.syncStatus === "uploaded",
      ).length;
    } finally {
      refreshing.value = false;
    }
  }
}

onMounted(() => {
  void refreshDiagnostics();
});

watch(
  theme,
  (value) => {
    if (typeof document !== "undefined") {
      document.documentElement.dataset.theme = value;
    }

    if (typeof window !== "undefined") {
      try {
        window.localStorage.setItem(themeStorageKey, value);
      } catch {
        // Theme persistence is best effort.
      }
    }
  },
  { immediate: true },
);
</script>

<template>
  <section class="about" aria-labelledby="about-heading">
    <p class="about__nav">
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </p>

    <h1 id="about-heading" class="about__heading">Settings</h1>

    <section class="about__settings" aria-labelledby="settings-heading">
      <h2 id="settings-heading" class="about__subheading">Display</h2>
      <div class="about__setting-row">
        <div>
          <h3>Theme</h3>
          <p>Choose how Meridian displays on this device.</p>
        </div>
        <div class="about__theme-toggle" aria-label="Theme">
          <button
            type="button"
            :aria-pressed="theme === 'light'"
            @click="setTheme('light')"
          >
            Light
          </button>
          <button
            type="button"
            :aria-pressed="theme === 'dark'"
            @click="setTheme('dark')"
          >
            Dark
          </button>
        </div>
      </div>
    </section>

    <!--
      Which pages appear in the navigation (M18.69).

      Its own section rather than a row under Display, because Display is about
      how this device draws things and this is about what the reader's account
      shows them everywhere. Hiding a page takes it out of both menus and off
      Home; it never takes away the page itself, which is what the note under
      the heading says plainly — somebody who hides a dashboard and then follows
      a link to one should not think the product is broken.
    -->
    <section class="about__pages" aria-labelledby="about-pages-heading">
      <h2 id="about-pages-heading" class="about__subheading">Pages</h2>
      <p class="about__section-note">
        What appears in your menus and on Home. Saved to your account, so it
        follows you to any device you sign in on. A hidden page still exists and
        still opens from a link.
      </p>

      <div
        v-for="page in hideablePages"
        :key="page.key"
        class="about__setting-row"
      >
        <div>
          <h3>{{ page.label }}</h3>
          <p>{{ page.description }}</p>
        </div>
        <div class="about__theme-toggle" :aria-label="page.label">
          <button
            type="button"
            :aria-pressed="isPageShown(page)"
            :disabled="pageVisibilityBusy !== null"
            @click="setPageShown(page, true)"
          >
            Show
          </button>
          <button
            type="button"
            :aria-pressed="!isPageShown(page)"
            :disabled="pageVisibilityBusy !== null"
            @click="setPageShown(page, false)"
          >
            Hide
          </button>
        </div>
      </div>

      <!--
        Said out loud rather than left as a control that snapped back. The
        setting lives on the account, so a device with no node cannot change it,
        and "nothing happened" is the one response that would leave the reader
        guessing.
      -->
      <p v-if="pageVisibilityError" class="about__pages-error" role="alert">
        {{ pageVisibilityError }}
      </p>
    </section>

    <!--
      What the menus carry (M18.69).

      Under Pages and separate from it, because it is the milder version of the
      same decision and the order says so: first what you keep at all, then what
      you work out of. Taking a page out of the menus leaves it on Home, which
      the note says outright — a reader who trims their menu down to the four
      pages they use hourly has to know the rest are still somewhere, or the
      control is one nobody dares to touch.

      The rows are the reader's own menu entries rather than a fixed list, so
      nobody is offered a page their session does not have.
    -->
    <section class="about__menus" aria-labelledby="about-menus-heading">
      <h2 id="about-menus-heading" class="about__subheading">Menus</h2>
      <p class="about__section-note">
        Which of your pages appear in the menus at the top of the screen.
        Everything here stays on Home whether or not it is in a menu, and still
        opens from a link or from search. Saved to your account, so your menus
        look the same on any device you sign in on.
      </p>

      <div
        v-for="page in menuPageRows"
        :key="page.key"
        class="about__setting-row"
      >
        <div>
          <h3>{{ page.label }}</h3>
          <p>{{ page.description }}</p>
        </div>
        <div class="about__theme-toggle" :aria-label="page.label">
          <button
            type="button"
            :aria-pressed="isPageInMenu(page.key)"
            :disabled="menuPageBusy !== null"
            @click="setInMenu(page.key, true)"
          >
            In menu
          </button>
          <button
            type="button"
            :aria-pressed="!isPageInMenu(page.key)"
            :disabled="menuPageBusy !== null"
            @click="setInMenu(page.key, false)"
          >
            Home only
          </button>
        </div>
      </div>

      <!--
        A reader with no session has no menu to describe, and one whose pages
        are all hidden has emptied it on purpose. Either way the honest thing to
        render is a sentence rather than a heading over nothing.
      -->
      <p v-if="menuPageRows.length === 0" class="about__section-note">
        Your menus are empty. Pages appear here once you are signed in to an
        event.
      </p>

      <p v-if="menuPageError" class="about__menus-error" role="alert">
        {{ menuPageError }}
      </p>
    </section>

    <!--
      Readiness has its own screen and no way in from here, which made it a page
      you reached by knowing the URL. The count is on the link rather than behind
      it because "5 of 8 ready" is the whole answer most of the time — the screen
      is for the times it is not (technical spec 14).
    -->
    <section class="about__device" aria-labelledby="about-device-heading">
      <h2 id="about-device-heading" class="about__subheading">This device</h2>
      <RouterLink class="about__readiness-link" :to="{ name: 'readiness' }">
        <span class="about__readiness-body">
          <strong>Device readiness</strong>
          <span>
            Advisory, only for you, and never shown to organizers.
          </span>
        </span>
        <span
          class="about__readiness-count"
          :data-complete="readiness.ready === readiness.total"
        >
          {{ readiness.ready }}/{{ readiness.total }} ready
        </span>
      </RouterLink>
    </section>

    <!--
      Cached-permission state (CLIENT-009; contract 19A.2). This is where it
      lives, rather than on every screen: it is a standing fact about this
      device's relationship with its node, which is what the rest of this page
      is about, and it sits above node health because "which permissions am I
      working from" and "can I reach the node" are the two halves of the same
      question.

      `show-when-current` because a page opened on purpose should answer the
      question it was opened for. A section that renders nothing whenever the
      answer is "fine" reads as broken at the moment it is working.
    -->
    <section
      class="about__permissions"
      aria-labelledby="about-permissions-heading"
    >
      <h2 id="about-permissions-heading" class="about__subheading">
        Permissions
      </h2>
      <SessionPermissionsNotice show-when-current />
      <p v-if="!sessionEstablished" class="about__permissions-empty">
        This device holds no session, so there are no permissions to report.
        Sign in to resolve them.
      </p>
    </section>

    <section class="about__health" aria-labelledby="about-health-heading">
      <header class="about__section-header">
        <h2 id="about-health-heading" class="about__subheading">
          Operational health
        </h2>
        <button
          type="button"
          class="about__refresh"
          :disabled="refreshing"
          @click="refreshDiagnostics"
        >
          {{ refreshing ? "Checking..." : "Refresh" }}
        </button>
      </header>

      <dl class="about__meta" aria-label="Operational health">
        <div>
          <dt>Node connection</dt>
          <dd>{{ nodeConnection.label }} - {{ nodeConnection.meaning }}</dd>
        </div>
        <div>
          <dt>API base URL</dt>
          <dd>{{ apiConfig.baseUrl }}</dd>
        </div>
        <div>
          <dt>Health URL</dt>
          <dd>{{ healthUrl }}</dd>
        </div>
        <div>
          <dt>Server health</dt>
          <dd>{{ serverHealthText }}</dd>
        </div>
        <div>
          <dt>Command auth</dt>
          <dd>
            <template v-if="signedInAs">
              Signed in as {{ signedInAs }}; commands are sent with this device's
              token.
            </template>
            <template v-else-if="hasCommandCredential">
              Authenticated by a shared-workstation session.
            </template>
            <template v-else>
              Not signed in; commands are held on this device.
            </template>
          </dd>
        </div>
        <div>
          <dt>Field session</dt>
          <dd>{{ fieldSessionText }}</dd>
        </div>
        <div>
          <dt>Command outbox</dt>
          <dd>
            {{ outboxText }}
            <ul v-if="rejectedCommands.length > 0" class="diagnostics__rejected">
              <li v-for="command in rejectedCommands" :key="command.key">
                {{ command.label
                }}<template v-if="command.detail"> — {{ command.detail }}</template
                >: {{ command.reason }}
              </li>
            </ul>
          </dd>
        </div>
        <div>
          <dt>FR command sync</dt>
          <dd>{{ fieldReportSyncText }}</dd>
        </div>
        <div>
          <dt>FR text outbox</dt>
          <dd>
            {{ pendingTextCount }} queued; {{ submittedReportCount }} local
            submitted report{{ submittedReportCount === 1 ? "" : "s" }}.
          </dd>
        </div>
        <div>
          <dt>FR photo outbox</dt>
          <dd>{{ photoSyncText }}</dd>
        </div>
      </dl>

      <dl class="about__meta about__meta--secondary" aria-label="Server details">
        <div>
          <dt>Server environment</dt>
          <dd>{{ serverEnvironment }}</dd>
        </div>
        <div>
          <dt>Config schema</dt>
          <dd>{{ configSchemaVersion }}</dd>
        </div>
        <div>
          <dt>Server timestamp</dt>
          <dd>{{ lastServerTimestamp }}</dd>
        </div>
      </dl>
    </section>

    <section class="about__about" aria-labelledby="about-app-heading">
      <h2 id="about-app-heading" class="about__subheading">About Meridian</h2>
      <dl class="about__meta" aria-label="Application version">
        <div>
          <dt>Client version</dt>
          <dd>{{ CLIENT_VERSION }}</dd>
        </div>
      </dl>
    </section>
  </section>
</template>

<style scoped>
.about {
  width: var(--m-content-narrow);
}

.diagnostics__rejected {
  margin: var(--m-space-2) 0 0;
  padding-left: var(--m-space-4);
  color: var(--m-text-secondary);
}

.about__nav {
  margin: 0 0 var(--m-space-4);
}

.about__nav a {
  color: var(--m-text-primary);
}

.about__nav a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.about__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.about__subheading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.about__settings,
.about__device,
.about__pages,
.about__menus,
.about__permissions,
.about__about {
  margin-top: var(--m-space-6);
}

.about__section-note {
  margin: var(--m-space-2) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.about__pages-error,
.about__menus-error {
  margin: var(--m-space-3) 0 0;
  color: var(--m-status-danger);
  font-size: var(--m-text-sm);
}

.about__permissions :deep(.session-permissions),
.about__permissions-empty {
  margin-top: var(--m-space-3);
}

.about__permissions-empty {
  margin-bottom: 0;
  color: var(--m-text-muted);
}

.about__readiness-link {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
  margin-top: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: inherit;
  text-decoration: none;
  cursor: pointer;
}

.about__readiness-link:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.about__readiness-body {
  display: grid;
  gap: var(--m-space-1);
  min-width: 0;
}

.about__readiness-body span {
  color: var(--m-text-muted);
}

/*
 * The count carries its own state, and says it in the number as well as in the
 * color: everything ready reads "8/8 ready" whether or not the green lands
 * (accessibility checklist).
 */
.about__readiness-count {
  padding: 0.15rem var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  background: var(--m-surface-base);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
  white-space: nowrap;
}

.about__readiness-count[data-complete="true"] {
  border-color: color-mix(
    in srgb,
    var(--m-status-success) 55%,
    var(--m-border-default)
  );
  background: color-mix(
    in srgb,
    var(--m-status-success) 16%,
    var(--m-surface-base)
  );
  color: color-mix(in srgb, var(--m-status-success) 78%, var(--m-text-primary));
}

.about__setting-row {
  display: grid;
  gap: var(--m-space-3);
  margin-top: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.about__setting-row h3,
.about__setting-row p {
  margin: 0;
}

.about__setting-row p {
  margin-top: var(--m-space-1);
  color: var(--m-text-muted);
}

.about__theme-toggle {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--m-space-1);
  padding: var(--m-space-1);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.about__theme-toggle button {
  min-height: 2.25rem;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--m-text-secondary);
  font: inherit;
  font-weight: 800;
  cursor: pointer;
}

.about__theme-toggle button[aria-pressed="true"] {
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

/* Matches the refresh button: the pressed state still reads while the write is
   in flight, so the control says what it holds rather than going blank. */
.about__theme-toggle button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.about__theme-toggle button:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.about__meta {
  display: grid;
  gap: var(--m-space-2);
  margin: var(--m-space-4) 0 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.about__meta div {
  display: grid;
  gap: var(--m-space-1);
}

.about__meta dt {
  margin: 0;
  color: var(--m-text-secondary);
  font-weight: 600;
}

.about__meta dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.about__health {
  margin-top: var(--m-space-8);
}

.about__section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.about__refresh {
  min-height: 2.5rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-text-primary);
  color: var(--m-surface-app);
  font: inherit;
  font-weight: 700;
}

.about__refresh:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.about__refresh:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.about__meta--secondary {
  background: var(--m-surface-base);
}

@media (min-width: 44rem) {
  .about__setting-row {
    grid-template-columns: minmax(0, 1fr) minmax(12rem, 16rem);
    align-items: center;
  }

  .about__meta div {
    grid-template-columns: 10rem minmax(0, 1fr);
    gap: var(--m-space-2);
  }
}
</style>
