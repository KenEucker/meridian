<script setup lang="ts">
import { computed, inject, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink, routerKey } from "vue-router";

import { meridianAppConfig, type MeridianAppConfig } from "@/app/appConfig";
import OfflineBanner from "@/components/OfflineBanner.vue";
import {
  useCombinedNavigation,
  useShowStaffMenu,
  useStaffLinks,
  useWorkflowLinks,
} from "@/components/workflowLinks";
import {
  fixtureDepartmentAccesses,
  fixtureDepartmentRoleSummary,
  selectFixtureDepartment,
  selectedFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";
import { useConnectivity } from "@/offline/useConnectivity";
import {
  describeConnectivityState,
  type ConnectivityState,
} from "@/offline/syncStatus";
import { syncAttendanceOutbox } from "@/shift-board/syncAttendanceOutbox";

type ThemeChoice = "light" | "dark";
const meridianMarkUrl = "/assets/brand/meridian-mark.png";
const themeStorageKey = "meridian.ui.theme";

const props = defineProps<{
  readonly config?: MeridianAppConfig;
}>();

const appConfig = computed(() => props.config ?? meridianAppConfig);
const router = inject(routerKey, null);
const fixtureUserMenuOpen = ref(false);
const workflowMenuOpen = ref(false);
const fixtureUserElement = ref<HTMLElement | null>(null);
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
const fixtureUserLabel = "Fixture user";
const connectionStatus = computed(() =>
  connectionStatusFor(connectivity.value),
);

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

function toggleFixtureUserMenu(): void {
  fixtureUserMenuOpen.value = !fixtureUserMenuOpen.value;
}

function closeFixtureUserMenu(): void {
  fixtureUserMenuOpen.value = false;
}

function switchFixtureDepartment(departmentId: string): void {
  selectFixtureDepartment(departmentId);
  closeFixtureUserMenu();
  void router?.push?.({ name: "staff.me" });
}

function handleFixtureUserOutsideClick(event: Event): void {
  const target = event.target;

  if (
    !fixtureUserMenuOpen.value ||
    !(target instanceof Node) ||
    fixtureUserElement.value?.contains(target)
  ) {
    return;
  }

  closeFixtureUserMenu();
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

function connectionStatusFor(state: ConnectivityState): {
  readonly label: string;
  readonly meaning: string;
  readonly tone: "connected" | "offline" | "failure";
} {
  const descriptor = describeConnectivityState(state);

  if (state === "sync_conflict" || state === "sync_failed") {
    return {
      label: "Offline with failures",
      meaning: descriptor.meaning,
      tone: "failure",
    };
  }

  if (
    state === "offline_usable" ||
    state === "central_unreachable" ||
    state === "sync_queued"
  ) {
    return {
      label: "Offline, no device errors",
      meaning: descriptor.meaning,
      tone: "offline",
    };
  }

  return {
    label: "Connected and fully capable",
    meaning: descriptor.meaning,
    tone: "connected",
  };
}

// Shared offline/sync status display for the shared client surfaces (M8.6). The
// banner is driven by the coarse device-connectivity view-model and is silent
// while online, so it appears only where offline state affects current work
// (UI implementation contract section 16.2). Richer sync states are fed through
// the same OfflineBanner view-model by later Alpha 1 milestones.
//
// When the device reports online, drain supported local operation outboxes to
// the local Meridian server command API.
const connectivity = useConnectivity();

watch(
  connectivity,
  (state) => {
    if (state === "online") {
      void syncFieldReportOutbox();
      void syncAttendanceOutbox();
    }
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

watch(fixtureUserMenuOpen, (isOpen) => {
  if (typeof document === "undefined") {
    return;
  }

  if (isOpen) {
    document.addEventListener("pointerdown", handleFixtureUserOutsideClick);
    return;
  }

  document.removeEventListener("pointerdown", handleFixtureUserOutsideClick);
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
    document.removeEventListener("pointerdown", handleFixtureUserOutsideClick);
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
    :aria-label="`${appConfig.productName} application shell`"
  >
    <header class="app-shell__top-bar">
      <div class="app-shell__masthead">
        <RouterLink
          class="app-shell__home"
          :to="{ name: 'home' }"
          :aria-label="appConfig.productName"
        >
          <img
            class="app-shell__mark"
            :src="meridianMarkUrl"
            alt=""
            width="44"
            height="44"
            aria-hidden="true"
          />
          <span class="app-shell__product">
            <span class="app-shell__product-name">Meridian</span>
            <span class="app-shell__mode-name">{{ appConfig.modeDisplayName }}</span>
          </span>
        </RouterLink>

        <div class="app-shell__context" aria-label="Current operations context">
          <p>{{ selectedFixtureDepartment.departmentLabel }}</p>
          <span>{{ selectedFixtureDepartment.eventLabel }}</span>
        </div>

        <div class="app-shell__actions">
          <div ref="fixtureUserElement" class="app-shell__user">
            <button
              type="button"
              class="app-shell__user-button"
              :data-connection-status="connectionStatus.tone"
              :aria-label="`${fixtureUserLabel}. ${connectionStatus.label}.`"
              :aria-expanded="fixtureUserMenuOpen"
              aria-haspopup="menu"
              @click="toggleFixtureUserMenu"
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
              <span class="app-shell__user-label">{{ fixtureUserLabel }}</span>
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
              v-if="fixtureUserMenuOpen"
              class="app-shell__user-menu"
              role="menu"
              aria-label="Fixture user menu"
            >
              <p>Local fixture</p>
              <strong>{{ fixtureUserLabel }}</strong>
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
                @click="closeFixtureUserMenu"
              >
                Settings
              </RouterLink>
              <button
                type="button"
                role="menuitem"
                aria-disabled="true"
                @click="closeFixtureUserMenu"
              >
                Switch user
              </button>
              <button
                v-if="appConfig.uiMode === 'admin'"
                type="button"
                role="menuitem"
                aria-disabled="true"
                @click="closeFixtureUserMenu"
              >
                Switch organization
              </button>
              <div
                class="app-shell__department-switch"
                role="group"
                aria-label="Switch department"
              >
                <p>Switch department</p>
                <button
                  v-for="department in fixtureDepartmentAccesses"
                  :key="department.departmentId"
                  type="button"
                  role="menuitemradio"
                  :aria-checked="
                    department.departmentId === selectedFixtureDepartment.departmentId
                  "
                  :data-selected="
                    department.departmentId === selectedFixtureDepartment.departmentId
                  "
                  @click="switchFixtureDepartment(department.departmentId)"
                >
                  <span>
                    <strong>{{ department.departmentLabel }}</strong>
                    <small>{{ fixtureDepartmentRoleSummary(department) }}</small>
                  </span>
                </button>
              </div>
              <button
                v-if="appConfig.uiMode === 'admin'"
                type="button"
                role="menuitem"
                aria-disabled="true"
                @click="closeFixtureUserMenu"
              >
                Switch event
              </button>
              <button
                type="button"
                role="menuitem"
                aria-disabled="true"
                @click="closeFixtureUserMenu"
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
    <main class="app-shell__main">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.app-shell {
  --m-app-content-max: min(100% - 2rem, 76rem);

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

.app-shell__masthead {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--m-space-3);
  min-width: 0;
  padding: var(--m-space-3);
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

.app-shell__mark {
  display: block;
  width: 2.25rem;
  height: 2.25rem;
  object-fit: contain;
}

.app-shell__product {
  display: grid;
  gap: 0.1rem;
  min-width: 0;
}

.app-shell__product-name {
  color: var(--m-text-primary);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
  font-weight: 800;
  line-height: 1;
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

.app-shell__context {
  display: grid;
  gap: 0.15rem;
  grid-column: 1 / -1;
  grid-row: 2;
  min-width: 0;
  padding: var(--m-space-2) 0 0;
  border-top: 1px solid var(--m-border-subtle);
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

.app-shell__actions {
  display: flex;
  align-items: center;
  justify-self: end;
  gap: var(--m-space-2);
  grid-column: 2;
  grid-row: 1;
  min-width: 0;
  max-width: 100%;
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

.app-shell__user-button[data-connection-status="connected"] .app-shell__user-icon {
  color: color-mix(in srgb, var(--m-status-success) 68%, var(--m-text-muted));
}

.app-shell__user-button[data-connection-status="offline"] .app-shell__user-icon {
  color: color-mix(in srgb, var(--m-status-warning) 64%, var(--m-text-muted));
}

.app-shell__user-button[data-connection-status="failure"] .app-shell__user-icon {
  color: color-mix(in srgb, var(--m-status-danger) 64%, var(--m-text-muted));
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

.app-shell__connection-note[data-connection-status="connected"]
  > span:first-child {
  background: color-mix(
    in srgb,
    var(--m-status-success) 68%,
    var(--m-text-muted)
  );
}

.app-shell__connection-note[data-connection-status="offline"]
  > span:first-child {
  background: color-mix(
    in srgb,
    var(--m-status-warning) 64%,
    var(--m-text-muted)
  );
}

.app-shell__connection-note[data-connection-status="failure"]
  > span:first-child {
  background: color-mix(
    in srgb,
    var(--m-status-danger) 64%,
    var(--m-text-muted)
  );
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

.app-shell__department-switch {
  display: grid;
  gap: var(--m-space-1);
  margin: var(--m-space-1) 0;
  padding: var(--m-space-2) 0;
  border-top: 1px solid var(--m-border-subtle);
  border-bottom: 1px solid var(--m-border-subtle);
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

.app-shell__offline-banner {
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

@media (min-width: 50rem) {
  .app-shell__masthead {
    grid-template-columns: auto minmax(14rem, 1fr) auto;
  }

  .app-shell__home {
    grid-column: 1;
  }

  .app-shell__context {
    grid-column: 2;
    grid-row: 1;
    padding: 0 0 0 var(--m-space-4);
    border-top: 0;
    border-left: 1px solid var(--m-border-subtle);
  }

  .app-shell__actions {
    grid-column: 3;
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
    --m-app-content-max: min(100% - 1rem, 76rem);
  }

  .app-shell__top-bar {
    gap: var(--m-space-2);
    padding-top: calc(var(--m-space-2) + env(safe-area-inset-top));
  }

  .app-shell__masthead {
    gap: var(--m-space-2);
    padding: var(--m-space-2);
  }

  .app-shell__mark {
    width: 2rem;
    height: 2rem;
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
</style>
