<script setup lang="ts">
import { computed, inject, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink, routerKey, useRoute } from "vue-router";

import { meridianAppConfig, type MeridianAppConfig } from "@/app/appConfig";
import BrandMark from "@/branding/BrandMark.vue";
import {
  brandingState,
  chromeIdentityName,
  chromeMarkUrl,
  findDepartmentBranding,
  showsEventIdentity,
} from "@/branding/brandingProfile";
import { departmentSurfaceAttributes } from "@/branding/departmentSurfaceScope";
import { applyDocumentTitle } from "@/branding/documentTitle";
import { applyFavicon } from "@/branding/favicon";
import OfflineBanner from "@/components/OfflineBanner.vue";
import {
  useCombinedNavigation,
  useShowStaffMenu,
  useStaffLinks,
  useWorkflowLinks,
} from "@/components/workflowLinks";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";
import { useConnectivity } from "@/offline/useConnectivity";
import CommandOutboxNotice from "@/outbox/CommandOutboxNotice.vue";
import {
  describeConnectivityState,
  type ConnectivityState,
} from "@/offline/syncStatus";
import { signedIn, signOut } from "@/session/apiLogin";
import {
  clientSessionState,
  refreshClientSessionOnReconnect,
} from "@/session/clientSession";
import {
  selectSessionDepartment,
  selectedSessionDepartment,
  sessionDepartmentAccesses,
  sessionDepartmentRoleSummary,
  sessionEventContext,
} from "@/session/sessionAccess";
import {
  sessionNodeLock,
  sessionOrganizationId,
  sessionOrganizationLabel,
  sessionSwitchingAvailable,
  sessionSwitchingUnavailableReason,
} from "@/session/sessionContext";
import { describeSwitchUnavailable } from "@/session/sessionContextCopy";
import SessionPermissionsNotice from "@/session/SessionPermissionsNotice.vue";

type ThemeChoice = "light" | "dark";
const meridianMarkUrl = "/assets/brand/meridian-mark.png";
const themeStorageKey = "meridian.ui.theme";

const props = defineProps<{
  readonly config?: MeridianAppConfig;
}>();

const appConfig = computed(() => props.config ?? meridianAppConfig);
const router = inject(routerKey, null);
const route = useRoute();

/*
 * Organization identity replaces Meridian's on signed-in product surfaces
 * (BRAND-002). The shell is one of the four places BRAND-002 names, alongside
 * the document title, generated PDF exports, and system email.
 *
 * The mark falls back compact mark → full lockup → generated lettermark
 * (BRAND-005). Meridian's own mark is used only when the organization has no
 * branding profile at all, which is also what keeps an unconfigured install
 * looking like Meridian rather than like a nameless product.
 */
const branding = computed(() => brandingState.profile);

/*
 * The name beside the mark, resolved from the same rule the mark is
 * (BRAND-029). They are one identity: an event's logo next to the producing
 * company's name — or next to "Meridian" — asks a staff member to recognise
 * something they have no reason to know, in the place they look to confirm
 * they are in the right app.
 */
const productName = computed(() => chromeIdentityName(branding.value));

/*
 * Whether the header is already carrying the event's identity.
 *
 * When it is, the context block drops the event name: it would otherwise print
 * the same string twice in one bar, a few centimetres apart. The department is
 * what the context block is left to say, and that is the part the header never
 * carries.
 */
const headerCarriesEvent = computed(() => showsEventIdentity(branding.value));
/*
 * The mark beside the product name.
 *
 * Precedence lives in `chromeMarkUrl` so the header, the favicon, and the
 * desktop window icon cannot drift apart: locked event's logo, then the
 * organization's compact mark, then its full lockup (BRAND-005, BRAND-028).
 * Meridian's own mark is used only when the organization has no branding
 * profile and no event mark applies, which is what keeps an unconfigured
 * install looking like Meridian rather than like a nameless product.
 */
const markUrl = computed(
  () => chromeMarkUrl(branding.value) ?? (branding.value.is_branded ? null : meridianMarkUrl),
);
const showLettermark = computed(() => markUrl.value === null);

// The current department, and whether this surface may take its background.
// Scoping lives in one place so a new screen is unscoped until someone adds
// it deliberately (BRAND-012).
const departmentBrandingAttributes = computed(() =>
  departmentSurfaceAttributes(
    typeof route?.name === "string" ? route.name : null,
    selectedSessionDepartment.value?.departmentId ?? null,
  ),
);

/**
 * The mark for one department the signed-in user has access to (BRAND-010).
 *
 * Resolved from the branding payload, falling back to a lettermark generated
 * from the department name — a department that has uploaded nothing still has
 * a mark, so the header never has a hole where one department sits.
 */
function departmentMark(department: {
  readonly departmentId: string;
  readonly departmentLabel: string;
}) {
  const branding = findDepartmentBranding(department.departmentId);

  return {
    id: department.departmentId,
    name: department.departmentLabel,
    logoUrl: branding?.logo_url ?? null,
    lettermark: branding?.lettermark ?? null,
    accentColor: branding?.accent ?? null,
  };
}

/*
 * The department the client is working in, or nothing at all.
 *
 * Nothing is a real state now that the context comes from the session response
 * (CLIENT-001): a client that has not resolved a session, or whose user is
 * associated with no department, has no department to name and says so by
 * leaving the block out rather than by printing a placeholder identity.
 */
const currentDepartment = computed(() => selectedSessionDepartment.value);
const currentDepartmentMark = computed(() =>
  currentDepartment.value === null
    ? null
    : departmentMark(currentDepartment.value),
);

/**
 * The other departments this user belongs to, as marks beside the user menu.
 *
 * The current department is excluded: it is already named in the context block
 * two elements to the left, and a switcher that offers the department you are
 * in is a control with nothing to do. The same switch still lives in the user
 * menu with role summaries; this is the one-click path for someone who works
 * across departments all day.
 */
const otherDepartmentMarks = computed(() =>
  sessionDepartmentAccesses.value
    .filter(
      (department) =>
        department.departmentId !== currentDepartment.value?.departmentId,
    )
    .map(departmentMark),
);
const userMenuOpen = ref(false);
const workflowMenuOpen = ref(false);
const userElement = ref<HTMLElement | null>(null);
const workflowMenuElement = ref<HTMLElement | null>(null);
const theme = ref<ThemeChoice>(readPreferredTheme());
const workflowLinks = useWorkflowLinks();
const staffLinks = useStaffLinks();
const navigation = useCombinedNavigation();
const showStaffMenu = useShowStaffMenu();
// A short nav reads better as one list than as two dropdowns the reader has to
// guess between, so the shell merges Staff and Workflows under the threshold.
const primaryMenuLabel = computed(() =>
  navigation.value.combined ? "Menu" : "Workflows",
);
const primaryMenuLinks = computed(() =>
  navigation.value.combined ? navigation.value.links : workflowLinks.value,
);
const staffMenuOpen = ref(false);
const staffMenuElement = ref<HTMLElement | null>(null);
/*
 * Who the shell says is signed in (CLIENT-001).
 *
 * The name comes from the session response and nowhere else. Before one
 * resolves there is no user to name, and the button says so rather than
 * borrowing an identity from bundled data.
 */
const userLabel = computed(
  () => clientSessionState.document?.user.name ?? "Not signed in",
);
const connectionStatus = computed(() =>
  connectionStatusFor(connectivity.value),
);

/*
 * Context switching in the user menu (M16.7; CLIENT-012, CLIENT-013).
 *
 * Links to the two context screens, never the switcher itself: the top bar must
 * not become the organization or event switcher, and switching happens on Home
 * or a dedicated context-switching surface (UI contract 4.3, 4.4, 6.1).
 *
 * Kiosk is excluded because a Kiosk requires pinned context setup rather than
 * selecting its own (contract mode matrix, "Home / context selection"). Field
 * and Admin both select context, so both get the entries when the session says
 * there is something to select.
 */
const showContextSwitching = computed(
  () => appConfig.value.uiMode !== "kiosk" && sessionSwitchingAvailable.value,
);

/*
 * Personal sign-in belongs to a personal device (M16.11; AUTH-018, AUTH-030).
 *
 * A Kiosk is somebody else's machine for five minutes at a time: it holds a
 * shared-workstation session rather than a token, code entry is its own surface,
 * and ending the session is the session bar's job. Offering "sign in" or "sign
 * out" in the shell menu there would be a second, wrong way to do both.
 */
const showsPersonalSignIn = computed(
  () => appConfig.value.uiMode !== "kiosk",
);

async function signOutOfDevice(): Promise<void> {
  closeUserMenu();

  await signOut();
  await router?.push?.({ name: "login" });
}

/**
 * Why the entries are absent, stated as one line rather than as a disabled
 * control (operating guide 8.3A).
 *
 * Only for the two states that are worth explaining. "One context" needs no
 * explanation — nobody misses a switcher they have nothing to switch to — and a
 * client with no session has a sign-in problem, not a context problem.
 */
const contextNotice = computed(() => {
  const reason = sessionSwitchingUnavailableReason.value;

  if (
    appConfig.value.uiMode === "kiosk" ||
    reason === null ||
    reason === "single_context" ||
    reason === "no_session"
  ) {
    return null;
  }

  return describeSwitchUnavailable(
    reason,
    sessionNodeLock.value?.eventLabel ?? null,
  );
});

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

function toggleUserMenu(): void {
  userMenuOpen.value = !userMenuOpen.value;
}

function closeUserMenu(): void {
  userMenuOpen.value = false;
}

function switchDepartment(departmentId: string): void {
  selectSessionDepartment(departmentId);
  closeUserMenu();
  void router?.push?.({ name: "staff.me" });
}

function handleUserOutsideClick(event: Event): void {
  const target = event.target;

  if (
    !userMenuOpen.value ||
    !(target instanceof Node) ||
    userElement.value?.contains(target)
  ) {
    return;
  }

  closeUserMenu();
}

function toggleWorkflowMenu(): void {
  workflowMenuOpen.value = !workflowMenuOpen.value;
}

function closeWorkflowMenu(): void {
  workflowMenuOpen.value = false;
}

function handleWorkflowMenuOutsideClick(event: Event): void {
  const target = event.target;

  if (
    !workflowMenuOpen.value ||
    !(target instanceof Node) ||
    workflowMenuElement.value?.contains(target)
  ) {
    return;
  }

  closeWorkflowMenu();
}

function toggleStaffMenu(): void {
  staffMenuOpen.value = !staffMenuOpen.value;
}

function closeStaffMenu(): void {
  staffMenuOpen.value = false;
}

function handleStaffMenuOutsideClick(event: Event): void {
  const target = event.target;

  if (
    !staffMenuOpen.value ||
    !(target instanceof Node) ||
    staffMenuElement.value?.contains(target)
  ) {
    return;
  }

  closeStaffMenu();
}

/**
 * Node connection scale shown on the user button and the dropdown dot.
 *
 * UI implementation contract section 16.3 defines four steps, worst to best:
 * unknown, failing, degraded, connected. The steps are a scale of notice, not a
 * restatement of the seven connectivity states in 16.1 — those stay the text
 * label, because state is never carried by colour alone.
 *
 * `unknown` is startup only: before the first connectivity result there is
 * nothing to claim, and claiming "connected" would be a lie the shell cannot
 * back up.
 */
function connectionStatusFor(state: ConnectivityState | null): {
  readonly label: string;
  readonly meaning: string;
  readonly tone: "unknown" | "failing" | "degraded" | "connected";
} {
  if (state === null) {
    return {
      label: "Checking node connection",
      meaning: "Connection state has not been determined yet.",
      tone: "unknown",
    };
  }

  const descriptor = describeConnectivityState(state);

  if (state === "sync_conflict" || state === "sync_failed") {
    return {
      label: "Node connection failing",
      meaning: descriptor.meaning,
      tone: "failing",
    };
  }

  if (state === "online") {
    return {
      label: "Connected and fully capable",
      meaning: descriptor.meaning,
      tone: "connected",
    };
  }

  return {
    label: "Node connection degraded",
    meaning: descriptor.meaning,
    tone: "degraded",
  };
}

// Shared offline/sync status display for the shared client surfaces (M8.6). The
// banner is driven by the coarse device-connectivity view-model and is silent
// while online, so it appears only where offline state affects current work
// (UI implementation contract section 16.2). Richer sync states are fed through
// the same OfflineBanner view-model by later Alpha 1 milestones.
//
// When the device reports online, drain the command outbox to the node. One
// call for every command the device holds (M16.10; technical spec 11A.5) —
// Field Report photo uploads ride along behind it, because a photo can only be
// attached to a report the node has already accepted (technical spec 18.2).
const connectivity = useConnectivity();

watch(
  connectivity,
  (state, previous) => {
    if (state === "online") {
      void syncFieldReportOutbox();
    }

    /*
     * Regaining connectivity also re-resolves the session, so a permission that
     * was taken away is gone as soon as the device can be told (CLIENT-010). It
     * is asked on the transition only, and only of a client that already holds a
     * session; the guard is inside the call so the rule stays in one place with
     * the rest of the session behavior.
     */
    void refreshClientSessionOnReconnect(state, previous);
  },
  { immediate: true },
);

/*
 * The tab icon follows the same mark the header does (BRAND-002, BRAND-028).
 *
 * Driven off `chromeMarkUrl` rather than off `markUrl`, because the two differ
 * in exactly one case: with no branding at all the header shows Meridian's PNG
 * mark while the tab wants Meridian's `.ico`, which is what `applyFavicon`
 * falls back to when handed null.
 */
watch(
  () => chromeMarkUrl(branding.value),
  (url) => applyFavicon(url),
  { immediate: true },
);

// The document title carries the organization's name, not Meridian's, once a
// branding profile exists (BRAND-002).
watch(
  [productName, () => route?.meta?.title, () => appConfig.value.modeDisplayName],
  ([name, screenTitle, modeName]) => {
    applyDocumentTitle({
      screen: typeof screenTitle === "string" ? screenTitle : null,
      productName: name,
      modeName,
    });
  },
  { immediate: true },
);

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
        // Theme persistence is a convenience; the visible toggle still works.
      }
    }
  },
  { immediate: true },
);

watch(userMenuOpen, (isOpen) => {
  if (typeof document === "undefined") {
    return;
  }

  if (isOpen) {
    document.addEventListener("pointerdown", handleUserOutsideClick);
    return;
  }

  document.removeEventListener("pointerdown", handleUserOutsideClick);
});

watch(workflowMenuOpen, (isOpen) => {
  if (typeof document === "undefined") {
    return;
  }

  if (isOpen) {
    document.addEventListener("pointerdown", handleWorkflowMenuOutsideClick);
    return;
  }

  document.removeEventListener("pointerdown", handleWorkflowMenuOutsideClick);
});

watch(staffMenuOpen, (isOpen) => {
  if (typeof document === "undefined") {
    return;
  }

  if (isOpen) {
    document.addEventListener("pointerdown", handleStaffMenuOutsideClick);
    return;
  }

  document.removeEventListener("pointerdown", handleStaffMenuOutsideClick);
});

onBeforeUnmount(() => {
  if (typeof document !== "undefined") {
    document.removeEventListener("pointerdown", handleUserOutsideClick);
    document.removeEventListener("pointerdown", handleWorkflowMenuOutsideClick);
    document.removeEventListener("pointerdown", handleStaffMenuOutsideClick);
  }
});
</script>

<template>
  <div
    class="app-shell"
    :class="`app-shell--${appConfig.uiMode}`"
    :data-ui-mode="appConfig.uiMode"
    :data-deployment-target="appConfig.deploymentTarget"
    :data-organization-branding="branding.document_attribute"
    v-bind="departmentBrandingAttributes"
    :aria-label="`${productName} ${appConfig.modeDisplayName} application shell`"
  >
    <header class="app-shell__top-bar">
      <div class="app-shell__masthead">
        <RouterLink
          class="app-shell__home"
          :to="{ name: 'home' }"
          :aria-label="`${productName} ${appConfig.modeDisplayName}`"
        >
          <img
            v-if="!showLettermark"
            class="app-shell__mark"
            :src="markUrl ?? meridianMarkUrl"
            alt=""
            width="50"
            height="50"
            aria-hidden="true"
          />
          <span v-else class="app-shell__mark app-shell__lettermark" aria-hidden="true">
            {{ branding.lettermark }}
          </span>
          <span class="app-shell__product">
            <span class="app-shell__product-name">{{ productName }}</span>
            <span class="app-shell__mode-name">{{ appConfig.modeDisplayName }}</span>
          </span>
        </RouterLink>

        <!--
          Present only once the session names a department. A client that has
          not resolved one has no operations context to state, and a placeholder
          in this spot is worse than a gap: this is the block a staff member
          reads to confirm which department they are filing against.
        -->
        <div
          v-if="currentDepartment && currentDepartmentMark"
          class="app-shell__context"
          aria-label="Current operations context"
        >
          <BrandMark
            class="app-shell__context-mark"
            :name="currentDepartmentMark.name"
            :logo-url="currentDepartmentMark.logoUrl"
            :lettermark="currentDepartmentMark.lettermark"
            :accent-color="currentDepartmentMark.accentColor"
            size="xxl"
          />
          <div class="app-shell__context-text">
            <p>{{ currentDepartment.departmentLabel }}</p>
            <span v-if="!headerCarriesEvent && sessionEventContext?.eventLabel">
              {{ sessionEventContext.eventLabel }}
            </span>
          </div>
        </div>
        <div v-else class="app-shell__context-placeholder"></div>

        <div class="app-shell__actions">
          <div
            v-if="otherDepartmentMarks.length > 0"
            class="app-shell__department-marks"
            role="group"
            aria-label="Switch to another of your departments"
          >
            <button
              v-for="department in otherDepartmentMarks"
              :key="department.id"
              type="button"
              class="app-shell__department-mark"
              :data-mark="department.logoUrl ? 'logo' : 'lettermark'"
              :title="`Switch to ${department.name}`"
              @click="switchDepartment(department.id)"
            >
              <BrandMark
                :name="department.name"
                :logo-url="department.logoUrl"
                :lettermark="department.lettermark"
                :accent-color="department.accentColor"
                size="xl"
                :label="`Switch to ${department.name}`"
              />
            </button>
          </div>

          <div ref="userElement" class="app-shell__user">
            <button
              type="button"
              class="app-shell__user-button"
              :data-connection-status="connectionStatus.tone"
              :aria-label="`${userLabel}. ${connectionStatus.label}.`"
              :aria-expanded="userMenuOpen"
              aria-haspopup="menu"
              @click="toggleUserMenu"
            >
              <svg
                class="app-shell__user-icon"
                aria-hidden="true"
                focusable="false"
                viewBox="0 0 24 24"
              >
                <path
                  d="M20 21a8 8 0 0 0-16 0"
                  fill="none"
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                />
                <circle
                  cx="12"
                  cy="7"
                  r="4"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                />
              </svg>
              <span class="app-shell__user-label">{{ userLabel }}</span>
              <svg
                class="app-shell__dropdown-icon app-shell__user-dropdown-icon"
                aria-hidden="true"
                focusable="false"
                viewBox="0 0 24 24"
              >
                <path
                  d="m6 9 6 6 6-6"
                  fill="none"
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                />
              </svg>
            </button>
            <div
              v-if="userMenuOpen"
              class="app-shell__user-menu"
              role="menu"
              aria-label="User menu"
            >
              <p>Signed in as</p>
              <strong>{{ userLabel }}</strong>
              <div
                class="app-shell__connection-note"
                :data-connection-status="connectionStatus.tone"
                role="status"
              >
                <span aria-hidden="true"></span>
                <span>
                  <strong>{{ connectionStatus.label }}</strong>
                  <small>{{ connectionStatus.meaning }}</small>
                </span>
              </div>
              <RouterLink
                role="menuitem"
                :to="{ name: 'settings.about' }"
                @click="closeUserMenu"
              >
                Settings
              </RouterLink>
              <button
                type="button"
                role="menuitem"
                aria-disabled="true"
                @click="closeUserMenu"
              >
                Switch user
              </button>
              <!--
                Operating context: the organization the session resolved to, and
                the way out of it (M16.7; CLIENT-011 through CLIENT-013).

                Links to the context screens, never the switcher itself. The top
                bar must not be the organization or event switcher (UI contract
                4.3, 6.1), and switching happens on a dedicated surface (4.4).
              -->
              <div
                v-if="showContextSwitching || contextNotice"
                class="app-shell__context-switch"
                role="group"
                aria-label="Operating context"
              >
                <p>Context</p>
                <strong v-if="sessionOrganizationLabel">
                  {{ sessionOrganizationLabel }}
                </strong>
                <template v-if="showContextSwitching">
                  <RouterLink
                    role="menuitem"
                    :to="{ name: 'organizations.index' }"
                    @click="closeUserMenu"
                  >
                    Switch organization
                  </RouterLink>
                  <RouterLink
                    v-if="sessionOrganizationId"
                    role="menuitem"
                    :to="{
                      name: 'organizations.events.index',
                      params: { organizationId: sessionOrganizationId },
                    }"
                    @click="closeUserMenu"
                  >
                    Switch event
                  </RouterLink>
                </template>
                <!--
                  No switcher, and why. Absent rather than disabled, because a
                  disabled control invites someone to keep trying something that
                  cannot work here (operating guide 8.3A) — but silence would
                  read as a control the client had lost, so the reason is stated
                  (technical spec 11A.3).
                -->
                <small
                  v-else-if="contextNotice"
                  class="app-shell__context-notice"
                  :data-reason="sessionSwitchingUnavailableReason"
                  role="status"
                >
                  <strong>{{ contextNotice.label }}</strong>
                  {{ contextNotice.meaning }}
                </small>
              </div>
              <!--
                The departments the session says this user is associated with,
                and the standing they hold in each. Absent entirely for a user
                with one department or none: a switcher with a single
                destination is a control with nothing to do.
              -->
              <div
                v-if="sessionDepartmentAccesses.length > 1"
                class="app-shell__department-switch"
                role="group"
                aria-label="Switch department"
              >
                <p>Switch department</p>
                <button
                  v-for="department in sessionDepartmentAccesses"
                  :key="department.departmentId"
                  type="button"
                  role="menuitemradio"
                  :aria-checked="
                    department.departmentId === currentDepartment?.departmentId
                  "
                  :data-selected="
                    department.departmentId === currentDepartment?.departmentId
                  "
                  @click="switchDepartment(department.departmentId)"
                >
                  <span>
                    <strong>{{ department.departmentLabel }}</strong>
                    <small>{{ sessionDepartmentRoleSummary(department) }}</small>
                  </span>
                </button>
              </div>
              <!--
                Signing in and out of this device (M16.11; AUTH-018).

                A Kiosk is excluded from both: a shared workstation holds a
                session rather than a token, it signs in by typed code on its own
                surface, and its sign-out is the session bar's (AUTH-030).
              -->
              <RouterLink
                v-if="showsPersonalSignIn && !signedIn"
                role="menuitem"
                :to="{ name: 'login' }"
                @click="closeUserMenu"
              >
                Sign in
              </RouterLink>
              <button
                v-else-if="showsPersonalSignIn"
                type="button"
                role="menuitem"
                @click="signOutOfDevice"
              >
                Sign out
              </button>
              <div class="app-shell__menu-theme" aria-label="Theme">
                <button
                  type="button"
                  aria-label="Light theme"
                  :aria-pressed="theme === 'light'"
                  @click="setTheme('light')"
                >
                  <span class="app-shell__theme-label app-shell__theme-label--long"
                    >Light</span
                  >
                  <span
                    class="app-shell__theme-label app-shell__theme-label--short"
                    aria-hidden="true"
                    >&#9728;</span
                  >
                </button>
                <button
                  type="button"
                  aria-label="Dark theme"
                  :aria-pressed="theme === 'dark'"
                  @click="setTheme('dark')"
                >
                  <span class="app-shell__theme-label app-shell__theme-label--long"
                    >Dark</span
                  >
                  <span
                    class="app-shell__theme-label app-shell__theme-label--short"
                    aria-hidden="true"
                    >&#9790;</span
                  >
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div
        ref="workflowMenuElement"
        class="app-shell__workflow-menu"
        :data-open="workflowMenuOpen"
      >
        <button
          type="button"
          class="app-shell__workflow-button"
          :aria-expanded="workflowMenuOpen"
          aria-controls="app-shell-workflow-tabs"
          @click="toggleWorkflowMenu"
        >
          <span>{{ primaryMenuLabel }}</span>
          <svg
            class="app-shell__dropdown-icon app-shell__workflow-icon"
            aria-hidden="true"
            focusable="false"
            viewBox="0 0 24 24"
          >
            <path
              d="m6 9 6 6 6-6"
              fill="none"
              stroke="currentColor"
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
            />
          </svg>
        </button>
        <nav
          id="app-shell-workflow-tabs"
          class="app-shell__tabs"
          :aria-label="
            navigation.combined ? 'Staff pages and event workflows' : 'Event workflows'
          "
        >
          <RouterLink
            v-for="link in primaryMenuLinks"
            :key="link.label"
            :to="link.to"
            class="app-shell__tab"
            @click="closeWorkflowMenu"
          >
            {{ link.label }}
          </RouterLink>
        </nav>
      </div>

      <div
        v-if="showStaffMenu"
        ref="staffMenuElement"
        class="app-shell__workflow-menu app-shell__staff-menu"
        :data-open="staffMenuOpen"
      >
        <button
          type="button"
          class="app-shell__workflow-button"
          :aria-expanded="staffMenuOpen"
          aria-controls="app-shell-staff-tabs"
          @click="toggleStaffMenu"
        >
          <span>Staff</span>
          <svg
            class="app-shell__dropdown-icon app-shell__workflow-icon"
            aria-hidden="true"
            focusable="false"
            viewBox="0 0 24 24"
          >
            <path
              d="m6 9 6 6 6-6"
              fill="none"
              stroke="currentColor"
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
            />
          </svg>
        </button>
        <nav
          id="app-shell-staff-tabs"
          class="app-shell__tabs"
          aria-label="Staff pages"
        >
          <RouterLink
            v-for="link in staffLinks"
            :key="link.label"
            :to="link.to"
            class="app-shell__tab app-shell__staff-tab"
            @click="closeStaffMenu"
          >
            {{ link.label }}
          </RouterLink>
        </nav>
      </div>
    </header>
    <OfflineBanner class="app-shell__offline-banner" :state="connectivity" />
    <!--
      Cached-permission state sits beside connectivity state, not inside it. A
      device can be online with stale permissions or offline with fresh ones, and
      merging the two indicators loses the distinction (contract 19A.2).
    -->
    <SessionPermissionsNotice class="app-shell__session-notice" />
    <!--
      Held and refused commands, in the shell rather than on the surface that
      issued them: a person who queued a check-in and moved on is the one
      CLIENT-017 is about, and they are no longer on that screen.
    -->
    <CommandOutboxNotice class="app-shell__outbox-notice" />
    <main class="app-shell__main">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.app-shell {
  --m-app-content-max: min(100% - 2rem, max(76rem, 94vw));

  display: flex;
  flex-direction: column;
  min-height: 100vh;
  min-height: 100dvh;
}

.app-shell__top-bar {
  display: grid;
  grid-template-columns: minmax(0, var(--m-app-content-max));
  justify-content: center;
  gap: var(--m-space-3);
  padding: calc(var(--m-space-4) + env(safe-area-inset-top)) 0
    var(--m-space-3);
  background: var(--m-surface-app);
  border-bottom: 1px solid var(--m-border-subtle);
}

/*
 * One row at every width: organization lockup, current department context,
 * then the department switchers and the user menu.
 *
 * It does not re-flow to two rows on a phone, and that is the constraint the
 * rest of this band is built around. The marks are how a user confirms which
 * event and which department they are in, and a masthead that stacks puts the
 * organization's name under a department's mark — two identities in a column,
 * reading as one lockup that means nothing. What gives instead is text: the
 * middle column is the only flexible one and every string in this row
 * truncates, so the row narrows by shortening names rather than by wrapping.
 */
.app-shell__masthead {
  display: grid;
  grid-template-columns: minmax(0, auto) minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--m-space-3);
  min-width: 0;
  /*
   * Block padding is deliberately thin. The identity marks are the tallest
   * thing in this row and they set the bar's height; padding around them buys
   * nothing but a taller bar, and every pixel spent there is a pixel the marks
   * do not get. Inline padding stays generous — that edge is a margin, not
   * wasted height.
   */
  padding: var(--m-space-1) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.app-shell__home {
  display: inline-flex;
  align-items: center;
  gap: var(--m-space-2);
  grid-column: 1;
  grid-row: 1;
  min-width: 0;
  font-weight: 600;
  text-decoration: none;
  color: var(--m-text-primary);
}

/*
 * The three mark sizes in this bar are fluid, not stepped.
 *
 * Fixed sizes behind breakpoints left dead zones: at 600px the marks were still
 * at their desktop size while the row had lost a third of its width, so the
 * department mark overflowed its column and the department and event names were
 * squeezed to nothing. Anything that scales the row has to scale with it
 * continuously, because the row is a single line at every width and there is no
 * wrap to absorb the mistake.
 *
 * The floor is what stays legible on the narrowest phone; the ceiling is the
 * size chosen for the desktop bar. Both marks share one scale so the
 * organization and the current department always read as the same weight.
 */
.app-shell__mark,
.app-shell__context .app-shell__context-mark {
  width: clamp(2.5rem, 7vw, 5rem);
  height: clamp(2.5rem, 7vw, 5rem);
}

.app-shell__mark {
  display: block;
  object-fit: contain;
}

/*
 * Generated fallback when the organization has a name but no mark (BRAND-005).
 * It sits on the platform primary color rather than on the department accent:
 * this is organization identity, and it must read the same in every department.
 */
.app-shell__lettermark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--m-radius-md);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-sm);
  font-weight: 800;
  letter-spacing: 0.02em;
}

.app-shell__product {
  display: grid;
  gap: 0.1rem;
  min-width: 0;
}

.app-shell__product-name {
  min-width: 0;
  overflow: hidden;
  color: var(--m-text-primary);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
  font-weight: 800;
  line-height: 1;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-shell__mode-name {
  min-width: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  letter-spacing: 0;
  line-height: 1.1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/*
 * Holds the middle column open when there is no department to name, so the
 * lockup and the user menu keep their places instead of the row re-flowing the
 * moment a session resolves.
 */
.app-shell__context-placeholder {
  grid-column: 2;
  grid-row: 1;
  min-width: 0;
}

.app-shell__context {
  display: flex;
  align-items: center;
  gap: var(--m-space-2);
  grid-column: 2;
  grid-row: 1;
  min-width: 0;
  padding: 0 0 0 var(--m-space-3);
  border-left: 1px solid var(--m-border-subtle);
}

/*
 * The department mark sits with the department name it belongs to, not with
 * the organization mark on the left. The two are different identities and
 * putting them side by side reads as one lockup.
 */
.app-shell__context-text {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.app-shell__context p,
.app-shell__context span {
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-shell__context p {
  color: var(--m-text-primary);
  font-weight: 800;
}

.app-shell__context span {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

/*
 * One row, always. The department switchers and the user menu are a single
 * control cluster; wrapping them onto two lines reads as two unrelated groups
 * and pushes the masthead taller than the lockup beside it.
 */
.app-shell__actions {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  justify-self: end;
  gap: var(--m-space-2);
  grid-column: 3;
  grid-row: 1;
  min-width: 0;
  max-width: 100%;
}

/*
 * The user's other departments, immediately left of the user menu.
 *
 * Marks only. A row of names would compete with the context block for the
 * reader's attention, and the point of these is recognition at a glance — the
 * full name is on the tooltip and on the accessible name, and the labelled
 * switch with role summaries is still in the user menu.
 */
.app-shell__department-marks {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  gap: var(--m-space-1);
  min-width: 0;
}

/*
 * Subordinate to the identity marks at every width, on the same fluid scale so
 * the ratio between them never inverts.
 */
.app-shell__department-marks .app-shell__department-mark :deep(.brand-mark) {
  width: clamp(1.75rem, 5vw, 4rem);
  height: clamp(1.75rem, 5vw, 4rem);
  font-size: clamp(0.65rem, 1.4vw, 1.4rem);
}

.app-shell__department-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  border: 0;
  border-radius: var(--m-radius-sm);
  background: transparent;
  cursor: pointer;
}

/*
 * A department that uploaded a logo is shown as that logo and nothing else —
 * no button border, no card behind it. The artwork is the control.
 *
 * A department with no logo has only two generated letters, which need an edge
 * to read as a thing you can click, so the outline is drawn there and only
 * there. Hover is carried by the box for a lettermark and by the artwork's own
 * opacity for a logo, so neither state depends on a border the logo does not
 * have.
 */
.app-shell__department-mark[data-mark="lettermark"] {
  /*
   * Inset shadow rather than a border, so a lettermark switcher and a logo
   * switcher occupy exactly the same box and sit on the same baseline. A real
   * border would make the outlined ones 2px larger and visibly misaligned in
   * the row.
   */
  box-shadow: inset 0 0 0 1px var(--m-border-default);
}

.app-shell__department-mark[data-mark="lettermark"]:hover {
  box-shadow: inset 0 0 0 1px var(--m-text-secondary);
  background: color-mix(in srgb, var(--m-surface-base) 80%, transparent);
}

.app-shell__department-mark[data-mark="logo"]:hover {
  opacity: 0.75;
}

.app-shell__department-mark:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.app-shell__menu-theme {
  display: inline-flex;
  justify-self: end;
  width: 60%;
  min-height: 2rem;
  margin-top: var(--m-space-2);
  overflow: hidden;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  background: color-mix(in srgb, var(--m-surface-base) 80%, transparent);
}

.app-shell__menu-theme button,
.app-shell__user-button,
.app-shell__user-menu button,
.app-shell__user-menu a,
.app-shell__tab {
  min-height: 2.5rem;
  border: 0;
  color: var(--m-text-secondary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.app-shell__menu-theme button {
  flex: 1 1 0;
  min-height: 2rem;
  min-width: 2rem;
  padding: 0 var(--m-space-2);
  background: transparent;
  cursor: pointer;
}

.app-shell__theme-label--short {
  display: none;
}

.app-shell__menu-theme button[aria-pressed="true"] {
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.app-shell__user {
  position: relative;
  min-width: 0;
}

.app-shell__user-button {
  display: inline-flex;
  align-items: center;
  gap: var(--m-space-2);
  max-width: min(12rem, 40vw);
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: color-mix(in srgb, var(--m-surface-base) 80%, transparent);
  cursor: pointer;
}

/*
 * The four-step scale is rendered at full strength. It was previously mixed
 * roughly two thirds with --m-text-muted, which is what flattened it into the
 * surrounding gray and lost the distinction it exists to carry.
 */
.app-shell__user-button[data-connection-status="unknown"] .app-shell__user-icon {
  color: var(--m-text-muted);
}

.app-shell__user-button[data-connection-status="failing"] .app-shell__user-icon {
  color: var(--m-status-danger);
}

.app-shell__user-button[data-connection-status="degraded"] .app-shell__user-icon {
  color: var(--m-status-warning);
}

.app-shell__user-button[data-connection-status="connected"] .app-shell__user-icon {
  color: var(--m-status-success);
}

.app-shell__user-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-shell__user-icon,
.app-shell__dropdown-icon {
  display: block;
  flex: 0 0 auto;
  width: 1rem;
  height: 1rem;
}

.app-shell__user-icon,
.app-shell__dropdown-icon {
  color: var(--m-text-muted);
}

.app-shell__dropdown-icon {
  transition: transform 160ms ease;
}

.app-shell__user-button[aria-expanded="true"] .app-shell__dropdown-icon,
.app-shell__workflow-menu[data-open="true"] .app-shell__workflow-icon {
  transform: rotate(180deg);
}

.app-shell__user-menu {
  position: absolute;
  right: 0;
  top: calc(100% + var(--m-space-2));
  z-index: 30;
  display: grid;
  gap: var(--m-space-1);
  width: min(18rem, calc(100vw - 2rem));
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-overlay);
  box-shadow: var(--m-shadow-overlay);
}

.app-shell__user-menu p,
.app-shell__user-menu > strong {
  margin: 0;
}

.app-shell__user-menu p {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.app-shell__user-menu > strong {
  margin-bottom: var(--m-space-2);
  color: var(--m-text-primary);
}

.app-shell__connection-note {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: var(--m-space-2);
  align-items: start;
  margin-bottom: var(--m-space-2);
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-subtle);
  border-radius: 6px;
  background: color-mix(in srgb, var(--m-surface-base) 78%, transparent);
}

.app-shell__connection-note > span:first-child {
  width: 0.6rem;
  height: 0.6rem;
  margin-top: 0.38rem;
  border-radius: var(--m-radius-pill);
  background: var(--m-text-muted);
}

.app-shell__connection-note[data-connection-status="unknown"]
  > span:first-child {
  background: var(--m-text-muted);
}

.app-shell__connection-note[data-connection-status="failing"]
  > span:first-child {
  background: var(--m-status-danger);
}

.app-shell__connection-note[data-connection-status="degraded"]
  > span:first-child {
  background: var(--m-status-warning);
}

.app-shell__connection-note[data-connection-status="connected"]
  > span:first-child {
  background: var(--m-status-success);
}

.app-shell__connection-note strong,
.app-shell__connection-note small {
  display: block;
}

.app-shell__connection-note strong {
  margin: 0;
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
}

.app-shell__connection-note small {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  line-height: 1.35;
}

.app-shell__user-menu a,
.app-shell__user-menu button {
  display: flex;
  align-items: center;
  width: 100%;
  min-height: 2.25rem;
  padding: 0 var(--m-space-2);
  border-radius: 6px;
  background: transparent;
  text-align: left;
  text-decoration: none;
}

.app-shell__user-menu button[aria-disabled="true"] {
  opacity: 0.5;
}

.app-shell__department-switch,
.app-shell__context-switch {
  display: grid;
  gap: var(--m-space-1);
  margin: var(--m-space-1) 0;
  padding: var(--m-space-2) 0;
  border-top: 1px solid var(--m-border-subtle);
  border-bottom: 1px solid var(--m-border-subtle);
}

.app-shell__context-switch > strong {
  padding: 0 var(--m-space-2);
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
}

/*
 * A statement, not a control: no hover, no pointer, nothing that reads as
 * something to press. It is here so the absence of the switcher is explained
 * rather than looking like a control the client lost.
 */
.app-shell__context-notice {
  display: block;
  padding: 0 var(--m-space-2);
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  line-height: 1.35;
}

.app-shell__context-notice strong {
  display: block;
  color: var(--m-text-secondary);
}

.app-shell__department-switch button {
  cursor: pointer;
}

.app-shell__department-switch button[data-selected="true"] {
  background: color-mix(
    in srgb,
    var(--m-action-secondary-bg) 14%,
    transparent
  );
  color: var(--m-text-primary);
}

.app-shell__department-switch button > span,
.app-shell__department-switch strong,
.app-shell__department-switch small {
  display: block;
}

.app-shell__department-switch button > span {
  min-width: 0;
}

.app-shell__department-switch strong {
  color: inherit;
  font-size: var(--m-text-sm);
}

.app-shell__department-switch small {
  margin-top: 0.1rem;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  line-height: 1.25;
}

.app-shell__tabs {
  display: none;
  gap: var(--m-space-2);
  min-width: 0;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  box-shadow: var(--m-shadow-sm);
}

.app-shell__workflow-menu[data-open="true"] .app-shell__tabs {
  display: grid;
}

.app-shell__tab {
  display: inline-flex;
  align-items: center;
  min-width: 0;
  padding: 0 var(--m-space-3);
  border-radius: 6px;
  background: transparent;
  text-decoration: none;
}

.app-shell__workflow-menu {
  display: grid;
  gap: var(--m-space-2);
  min-width: 0;
}

.app-shell__workflow-button {
  display: flex;
  min-height: 2.75rem;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-2);
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 900;
  cursor: pointer;
}

.app-shell__workflow-menu[data-open="true"] .app-shell__workflow-button {
  border-bottom-right-radius: 6px;
  border-bottom-left-radius: 6px;
}

.app-shell__tab.router-link-active,
.app-shell__tab.router-link-exact-active {
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
}

.app-shell__home:focus-visible,
.app-shell__menu-theme button:focus-visible,
.app-shell__user-button:focus-visible,
.app-shell__user-menu a:focus-visible,
.app-shell__user-menu button:focus-visible,
.app-shell__workflow-button:focus-visible,
.app-shell__tab:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.app-shell__offline-banner,
.app-shell__session-notice,
.app-shell__outbox-notice {
  width: var(--m-app-content-max);
  margin: var(--m-space-3) auto 0;
}

.app-shell__main {
  display: flex;
  justify-content: center;
  flex: 1;
  width: 100%;
  padding: var(--m-space-6) var(--m-space-4)
    max(var(--m-space-8), env(safe-area-inset-bottom));
  background: var(--m-surface-app);
}

/*
 * The department surface background (BRAND-012). Applied only where the shell
 * has marked the surface department-scoped, and only to the content area —
 * the top bar carries organization identity and stays on the organization
 * canvas so the department never appears to own the whole product.
 *
 * `--m-department-surface` defaults to the organization surface, so this rule
 * is a no-op for a department with no background, for an organization that has
 * switched overrides off, and in dark mode.
 */
.app-shell[data-department-surface] .app-shell__main {
  background: var(--m-department-surface);
}

@media (min-width: 50rem) {
  /*
   * The middle column gets a floor once there is room for one, so the context
   * stops giving up width to the lockup and the switchers on a wide screen.
   * Below this the floor is dropped rather than the row being broken.
   */
  .app-shell__masthead {
    grid-template-columns: minmax(0, auto) minmax(14rem, 1fr) auto;
  }

  .app-shell__context {
    padding-left: var(--m-space-4);
  }

  .app-shell__workflow-button {
    display: none;
  }

  .app-shell__tabs,
  .app-shell__workflow-menu[data-open="true"] .app-shell__tabs {
    display: flex;
    gap: var(--m-space-1);
    padding: var(--m-space-1);
    box-shadow: none;
  }

  .app-shell__tab {
    flex: 0 0 auto;
    white-space: nowrap;
  }
}

@media (max-width: 32rem) {
  .app-shell {
    --m-app-content-max: min(100% - 1rem, max(76rem, 94vw));
  }

  .app-shell__top-bar {
    gap: var(--m-space-2);
    padding-top: calc(var(--m-space-2) + env(safe-area-inset-top));
  }

  /*
   * The row still does not wrap here; it gets denser.
   *
   * Everything fixed-width in it shrinks together — both identity marks, the
   * switchers, and the gaps between them — because the alternative is the
   * middle column being squeezed to nothing and the department mark sliding
   * under the switchers. Text is what absorbs the rest: the organization name,
   * the department, and the event all truncate.
   */
  .app-shell__masthead {
    gap: var(--m-space-2);
    padding: var(--m-space-1) var(--m-space-2);
  }

  .app-shell__context {
    gap: var(--m-space-1);
    padding-left: var(--m-space-2);
  }

  .app-shell__product-name {
    font-size: var(--m-text-md);
  }

  .app-shell__actions {
    gap: var(--m-space-1);
  }

  .app-shell__theme-label--long {
    display: none;
  }

  .app-shell__theme-label--short {
    display: inline;
  }

  /*
   * The switcher marks stay on a phone rather than being hidden. They are the
   * only one-tap way between departments, and the space they cost is a row
   * that already collapses the user button to an icon for the same reason.
   * Their size comes from the fluid scale above; nothing is stepped here.
   */
  .app-shell__user-button {
    min-height: 2.25rem;
    width: 2.25rem;
    justify-content: center;
    padding: 0 var(--m-space-2);
  }

  .app-shell__user-label,
  .app-shell__user-dropdown-icon {
    display: none;
  }

  .app-shell__user-icon {
    color: var(--m-text-primary);
  }
}

/*
 * The narrowest phones. The organization's name goes and its mark stays.
 *
 * By this width something has to give or the department and event names are
 * squeezed to nothing, and of the three strings the organization's is the one
 * already answered elsewhere — its mark is immediately to the left of where the
 * name was, and the document title carries it in full. Which department and
 * which event you are in is the thing a user is reading this bar to check, and
 * it is what an event-locked install exists to make obvious.
 */
@media (max-width: 24rem) {
  .app-shell__product {
    display: none;
  }
}
</style>
