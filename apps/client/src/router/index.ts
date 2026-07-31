import { watch } from "vue";
import {
  createRouter,
  createWebHistory,
  type Router,
  type RouteRecordRaw,
} from "vue-router";

import { holdsMeridianCredential } from "@/api/meridianApi";
import { meridianAppConfig } from "@/app/appConfig";
import { clientSessionState } from "@/session/clientSession";
import {
  departmentBrandingRouteProps,
  organizationBrandingRouteProps,
} from "@/branding/brandingRouteProps";
import DepartmentBrandingView from "@/views/DepartmentBrandingView.vue";
import OrganizationBrandingView from "@/views/OrganizationBrandingView.vue";
import {
  installDevelopmentFieldSession,
  resolveFieldSession,
} from "@/field-reports/fieldSession";
import AboutView from "@/views/AboutView.vue";
import DepartmentOverviewView from "@/views/DepartmentOverviewView.vue";
import DocumentEditView from "@/views/DocumentEditView.vue";
import EventContextView from "@/views/EventContextView.vue";
import DocumentLibraryView from "@/views/DocumentLibraryView.vue";
import FieldReportCreateView from "@/views/FieldReportCreateView.vue";
import FieldReportDetailView from "@/views/FieldReportDetailView.vue";
import FieldReportsIndexView from "@/views/FieldReportsIndexView.vue";
import EventInfoView from "@/views/EventInfoView.vue";
import HomeView from "@/views/HomeView.vue";
import KioskHomeView from "@/views/KioskHomeView.vue";
import KioskSafeTimeoutView from "@/views/KioskSafeTimeoutView.vue";
import KioskWorkstationLoginView from "@/views/KioskWorkstationLoginView.vue";
import LoginCodeView from "@/views/LoginCodeView.vue";
import LoginView from "@/views/LoginView.vue";
import { workstationSessionState } from "@/session/workstationSession";
import {
  installDevelopmentIncidentSession,
  resolveIncidentSession,
} from "@/ims/incidentReadModel";
import IncidentEditView from "@/views/IncidentEditView.vue";
import IncidentListView from "@/views/IncidentListView.vue";
import ImsRestrictedView from "@/views/ImsRestrictedView.vue";
import ImsFieldReportListView from "@/views/ImsFieldReportListView.vue";
import ImsFieldReportDetailView from "@/views/ImsFieldReportDetailView.vue";
import LogisticsDeskView from "@/views/LogisticsDeskView.vue";
import MeView from "@/views/MeView.vue";
import NotFoundView from "@/views/NotFoundView.vue";
import OperationsCenterView from "@/views/OperationsCenterView.vue";
import OrganizationContextView from "@/views/OrganizationContextView.vue";
import OrganizerDepartmentEditView from "@/views/OrganizerDepartmentEditView.vue";
import OrganizerDepartmentListView from "@/views/OrganizerDepartmentListView.vue";
import OrganizerStaffView from "@/views/OrganizerStaffView.vue";
import DepartmentEquipmentView from "@/views/DepartmentEquipmentView.vue";
import DepartmentShiftEditView from "@/views/DepartmentShiftEditView.vue";
import DepartmentShiftListView from "@/views/DepartmentShiftListView.vue";
import DepartmentTeamEditView from "@/views/DepartmentTeamEditView.vue";
import DepartmentTeamsListView from "@/views/DepartmentTeamsListView.vue";
import DepartmentTrainingDetailView from "@/views/DepartmentTrainingDetailView.vue";
import DepartmentTrainingEditView from "@/views/DepartmentTrainingEditView.vue";
import DepartmentTrainingListView from "@/views/DepartmentTrainingListView.vue";
import PlanningTableView from "@/views/PlanningTableView.vue";
import ReadinessView from "@/views/ReadinessView.vue";
import TeamOverviewView from "@/views/TeamOverviewView.vue";
import { selectSessionDepartment } from "@/session/sessionAccess";
import {
  installDevelopmentDepartmentSelfAdminSession,
  resolveDepartmentSelfAdminSession,
} from "@/department-teams/fixtureDepartmentSession";

/**
 * Until auth and event selection land, author Field Report surfaces install a
 * clearly labeled development session so list/create/detail remain
 * exercisable in the shared client shell (M9.4).
 */
function ensureFieldSession(): void {
  if (!resolveFieldSession()) {
    installDevelopmentFieldSession();
  }
}

/**
 * Development IMS session until auth/event selection own the real IC context.
 * The screen components still fail closed when the installed role lacks IC
 * authority (M11.5).
 */
function ensureIncidentSession(): void {
  if (!resolveIncidentSession()) {
    installDevelopmentIncidentSession();
  }
}

/**
 * Development department self-admin session until auth owns the real
 * department-lead context. Screens still fail closed without administer
 * authority (M11.13).
 *
 * The department in the route is also what the client is working in, so it is
 * recorded as the session's department selection (M16.6). Following the URL
 * rather than the other way round is what makes a deep link land in the right
 * department: the shell, the navigation, and the surface then all read the same
 * selection, and the capabilities that apply are the ones the session response
 * carries for it.
 */
function ensureDepartmentSelfAdminSession(to: {
  params: Record<string, string | string[]>;
}): void {
  if (typeof to.params.departmentId === "string") {
    selectSessionDepartment(to.params.departmentId);
  }

  if (!resolveDepartmentSelfAdminSession()) {
    installDevelopmentDepartmentSelfAdminSession();
  }
}

/**
 * A kiosk surface that needs somebody signed in (M16.9; technical spec 13.3).
 *
 * A locked workstation goes to the login screen instead. It is not the security
 * boundary — the node authenticates every request from the session key, and a
 * workstation with no session holds no key — it is what keeps a screen that would
 * render nothing from being reachable by typing a URL.
 */
function requireWorkstationSession() {
  return workstationSessionState.status === "active"
    ? true
    : { name: "kiosk.workstation-login" };
}

/**
 * Code entry, refused while somebody is signed in.
 *
 * Technical spec 13.3 requires the current user to end their session before
 * another signs in, so the login surface is not reachable from a live session —
 * "there is no quiet handover" (kiosk guide 4.4).
 */
function refuseWorkstationSwitch() {
  return workstationSessionState.status === "active" ? { name: "kiosk.home" } : true;
}

function legacyShiftBoardRedirect(surface: string) {
  return {
    path: `/events/:eventId/departments/:departmentId/shift-board/${surface}`,
    name: `events.departments.shift-board.${surface}`,
    redirect: (to: { params: Record<string, string | string[]> }) => ({
      name:
        surface === "current"
          ? "events.departments.overview"
          : surface === "logistics"
            ? "events.departments.logistics"
            : surface === "operations"
              ? "events.departments.operations"
              : "events.departments.planning",
      params: {
        eventId: to.params.eventId,
        departmentId: to.params.departmentId,
      },
    }),
  };
}

// Domain routes use UI Implementation Contract section 12 route names.
export const routes: RouteRecordRaw[] = [
  {
    path: "/",
    name: "home",
    component: HomeView,
  },
  /*
   * The two context-switching surfaces (UI contract 12.2; M16.7). Both are
   * always routable and both refuse for themselves: a client that may not
   * switch is told why rather than being sent to a not-found page, which is the
   * difference between a control that is absent and a screen that is missing.
   */
  {
    path: "/organizations",
    name: "organizations.index",
    component: OrganizationContextView,
  },
  {
    path: "/organizations/:organizationId/events",
    name: "organizations.events.index",
    component: EventContextView,
  },
  /*
   * Sign-in (UI contract 12.1; M16.11; AUTH-018, AUTH-019).
   *
   * Public, and public is the only thing they can be: they exist for a client
   * that holds no credential. Nothing else is gated on them — a surface with no
   * capabilities renders nothing, and the node refuses every request that
   * arrives without a token — so these are the way in rather than a wall around
   * everything else.
   */
  {
    path: "/login",
    name: "login",
    component: LoginView,
  },
  {
    path: "/login/code",
    name: "auth.code.entry",
    component: LoginCodeView,
  },
  {
    path: "/readiness",
    name: "readiness",
    component: ReadinessView,
  },
  {
    path: "/settings/about",
    name: "settings.about",
    component: AboutView,
  },
  {
    path: "/events/:eventId/departments/:departmentId/overview",
    name: "events.departments.overview",
    component: DepartmentOverviewView,
  },
  {
    path: "/events/:eventId/info",
    name: "events.info",
    component: EventInfoView,
  },
  {
    path: "/events/:eventId/departments/:departmentId/logistics",
    name: "events.departments.logistics",
    component: LogisticsDeskView,
  },
  {
    path: "/events/:eventId/departments/:departmentId/operations",
    name: "events.departments.operations",
    component: OperationsCenterView,
  },
  {
    path: "/events/:eventId/departments/:departmentId/planning",
    name: "events.departments.planning",
    component: PlanningTableView,
  },
  {
    path: "/events/:eventId/departments/:departmentId/admin",
    name: "events.departments.teams.index",
    component: DepartmentTeamsListView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/documents",
    name: "events.departments.documents.index",
    component: DocumentLibraryView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/documents/:artifactKind/create",
    name: "events.departments.documents.create",
    component: DocumentEditView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/documents/:artifactKind/:artifactId/edit",
    name: "events.departments.documents.edit",
    component: DocumentEditView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/trainings",
    name: "events.departments.trainings.index",
    component: DepartmentTrainingListView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/trainings/create",
    name: "events.departments.trainings.create",
    component: DepartmentTrainingEditView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/trainings/:trainingId/edit",
    name: "events.departments.trainings.edit",
    component: DepartmentTrainingEditView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/trainings/:trainingId",
    name: "events.departments.trainings.show",
    component: DepartmentTrainingDetailView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/teams",
    redirect: (to: { params: Record<string, string | string[]> }) => ({
      name: "events.departments.teams.index",
      params: {
        eventId: to.params.eventId,
        departmentId: to.params.departmentId,
      },
    }),
  },
  {
    path: "/events/:eventId/departments/:departmentId/shifts",
    name: "events.departments.shifts.index",
    component: DepartmentShiftListView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/shifts/create",
    name: "events.departments.shifts.create",
    component: DepartmentShiftEditView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/shifts/:shiftId/edit",
    name: "events.departments.shifts.edit",
    component: DepartmentShiftEditView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/equipment",
    name: "events.departments.equipment.index",
    component: DepartmentEquipmentView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/branding",
    name: "events.departments.branding",
    component: DepartmentBrandingView,
    props: departmentBrandingRouteProps,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/teams/create",
    name: "events.departments.teams.create",
    component: DepartmentTeamEditView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/teams/:teamId/edit",
    name: "events.departments.teams.edit",
    component: DepartmentTeamEditView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  {
    path: "/events/:eventId/departments/:departmentId/teams/:teamId",
    name: "events.departments.teams.show",
    component: TeamOverviewView,
    beforeEnter: ensureDepartmentSelfAdminSession,
  },
  legacyShiftBoardRedirect("current"),
  legacyShiftBoardRedirect("logistics"),
  legacyShiftBoardRedirect("operations"),
  legacyShiftBoardRedirect("planning"),
  {
    path: "/staff/me",
    name: "staff.me",
    component: MeView,
    beforeEnter: ensureFieldSession,
  },
  {
    path: "/staff/field-reports",
    name: "staff.field-reports.index",
    component: FieldReportsIndexView,
    beforeEnter: ensureFieldSession,
  },
  {
    path: "/staff/field-reports/create",
    name: "staff.field-reports.create",
    component: FieldReportCreateView,
    beforeEnter: ensureFieldSession,
  },
  {
    path: "/staff/field-reports/:fieldReportId",
    name: "staff.field-reports.show",
    component: FieldReportDetailView,
    beforeEnter: ensureFieldSession,
  },
  {
    path: "/ims/incidents",
    name: "ims.incidents.index",
    component: IncidentListView,
    beforeEnter: ensureIncidentSession,
  },
  {
    path: "/ims/field-reports",
    name: "ims.field-reports.index",
    component: ImsFieldReportListView,
    beforeEnter: ensureIncidentSession,
  },
  // Dictated Field Report create. Static segment before the `:fieldReportId`
  // route so `/ims/field-reports/create` is not read as a report id. Both
  // sessions are required: the incident session carries the IC permission that
  // gates dictation, and the field session carries the event/device context a
  // Field Report is created from.
  {
    path: "/ims/field-reports/create",
    name: "ims.field-reports.create",
    component: FieldReportCreateView,
    beforeEnter: [ensureIncidentSession, ensureFieldSession],
  },
  {
    path: "/ims/field-reports/:fieldReportId",
    name: "ims.field-reports.show",
    component: ImsFieldReportDetailView,
    beforeEnter: ensureIncidentSession,
  },
  {
    path: "/ims/incidents/create",
    name: "ims.incidents.create",
    component: IncidentEditView,
    beforeEnter: ensureIncidentSession,
  },
  {
    path: "/ims/incidents/:incidentId/edit",
    name: "ims.incidents.edit",
    component: IncidentEditView,
    beforeEnter: ensureIncidentSession,
  },
  {
    path: "/ims/incidents/:incidentId",
    name: "ims.incidents.show",
    component: IncidentEditView,
    beforeEnter: ensureIncidentSession,
  },
  {
    path: "/ims/restricted",
    name: "ims.restricted",
    component: ImsRestrictedView,
    beforeEnter: ensureIncidentSession,
  },
  {
    path: "/organizer/staff",
    name: "organizer.staff.index",
    component: OrganizerStaffView,
  },
  {
    path: "/organizer/departments",
    name: "organizer.departments.index",
    component: OrganizerDepartmentListView,
  },
  {
    path: "/organizer/departments/create",
    name: "organizer.departments.create",
    component: OrganizerDepartmentEditView,
  },
  {
    path: "/organizer/departments/:departmentId/edit",
    name: "organizer.departments.edit",
    component: OrganizerDepartmentEditView,
  },
  {
    path: "/organizer/branding",
    name: "organizer.branding",
    component: OrganizationBrandingView,
    props: organizationBrandingRouteProps,
  },
  {
    path: "/organizer/documents",
    name: "organizer.documents.index",
    component: DocumentLibraryView,
  },
  {
    path: "/organizer/documents/:artifactKind/create",
    name: "organizer.documents.create",
    component: DocumentEditView,
  },
  {
    path: "/organizer/documents/:artifactKind/:artifactId/edit",
    name: "organizer.documents.edit",
    component: DocumentEditView,
  },
  /*
   * Kiosk surfaces (UI contract 12.8; M16.9). Three of the six exist: the two a
   * shared-workstation session begins and ends at, and the dashboard it holds
   * open. `kiosk.switch-user`, `kiosk.reauth`, and `kiosk.shift-board` are their
   * own tasks.
   *
   * The safe-timeout surface is reachable whether or not a session is live,
   * because a timeout is precisely the case where there is no session left to
   * check by the time somebody arrives at it.
   */
  {
    path: "/kiosk",
    name: "kiosk.home",
    component: KioskHomeView,
    beforeEnter: requireWorkstationSession,
  },
  {
    path: "/kiosk/sign-in",
    name: "kiosk.workstation-login",
    component: KioskWorkstationLoginView,
    beforeEnter: refuseWorkstationSwitch,
  },
  {
    path: "/kiosk/timed-out",
    name: "kiosk.safe-timeout",
    component: KioskSafeTimeoutView,
  },
  {
    path: "/:pathMatch(.*)*",
    name: "not-found",
    component: NotFoundView,
  },
];

/**
 * Routes a client with nothing may still reach.
 *
 * The sign-in pair, because they are how a client stops having nothing. The
 * Kiosk surfaces, because a shared workstation holds a session key rather than a
 * token and signs in by typed code on its own screen (AUTH-030) — sending it to
 * the personal sign-in screen would be sending it somewhere it cannot use. And
 * not-found, which is not a surface anybody was denied.
 */
const PUBLIC_ROUTE_NAMES: readonly string[] = [
  "login",
  "auth.code.entry",
  "not-found",
];

function isPublicRoute(name: unknown): boolean {
  return (
    typeof name === "string" &&
    (PUBLIC_ROUTE_NAMES.includes(name) || name.startsWith("kiosk."))
  );
}

/**
 * Send a client that holds nothing to sign in (M16.11; AUTH-018).
 *
 * Not a security boundary — the node authenticates every request and refuses one
 * carrying no credential regardless of what the client rendered (technical spec
 * 11A.2). It is about not leaving somebody on a screen that can only be empty:
 * a device whose token was revoked, or one that has never signed in, has no
 * capabilities and would render a shell with nothing in it.
 *
 * Both conditions are required. A client holding a cached session but no live
 * credential is an offline device mid-event, and bouncing it to a login screen
 * it cannot complete is precisely the failure the cache exists to prevent
 * (CLIENT-007).
 *
 * A client still resolving its first session is not sent anywhere. At the first
 * navigation the node has not answered yet, and "holds nothing" is not yet a
 * fact about the client — it is a fact about how far the boot has got. Whoever
 * started that resolution decides once it settles.
 */
export function requiresSignIn(name: unknown): boolean {
  if (
    isPublicRoute(name) ||
    meridianAppConfig.uiMode === "kiosk" ||
    clientSessionState.refreshing
  ) {
    return false;
  }

  return !holdsMeridianCredential() && clientSessionState.document === null;
}

export const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach((to) =>
  requiresSignIn(to.name) ? { name: "login" } : true,
);

/**
 * Move a client to sign in the moment it stops holding a session (AUTH-023).
 *
 * The route guard only runs on a navigation, and losing a credential is not one:
 * a device whose token is revoked while somebody is looking at a surface would
 * sign out in the shell and leave them standing on a screen whose contents they
 * are no longer entitled to. This watches the session itself, so the answer
 * arrives with the refusal rather than with the next click.
 *
 * `replace`, not `push`: the surface nobody may see should not be the place the
 * back button returns to.
 *
 * Returns the watch stopper, which the specs use; the application installs this
 * once and never stops it.
 */
export function redirectWhenSignedOut(target: Router = router): () => void {
  return watch(
    () => clientSessionState.document,
    (document) => {
      if (document === null && requiresSignIn(target.currentRoute.value.name)) {
        void target.replace({ name: "login" });
      }
    },
  );
}
