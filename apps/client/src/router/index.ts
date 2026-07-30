import { createRouter, createWebHistory, type RouteRecordRaw } from "vue-router";

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
  installDevelopmentOrganizerDepartmentSession,
  resolveOrganizerDepartmentSession,
} from "@/organizer-departments/departmentAdminModel";
import {
  installDevelopmentDepartmentSelfAdminSession,
  resolveDepartmentSelfAdminSession,
} from "@/department-teams/teamAdminModel";

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
 * Development organizer session until auth and organization selection own the
 * real organizer context. Screen components still fail closed when the
 * installed role lacks organizer authority (M11.12).
 */
function ensureOrganizerDepartmentSession(): void {
  if (!resolveOrganizerDepartmentSession()) {
    installDevelopmentOrganizerDepartmentSession();
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
    beforeEnter: ensureOrganizerDepartmentSession,
  },
  {
    path: "/organizer/departments",
    name: "organizer.departments.index",
    component: OrganizerDepartmentListView,
    beforeEnter: ensureOrganizerDepartmentSession,
  },
  {
    path: "/organizer/departments/create",
    name: "organizer.departments.create",
    component: OrganizerDepartmentEditView,
    beforeEnter: ensureOrganizerDepartmentSession,
  },
  {
    path: "/organizer/departments/:departmentId/edit",
    name: "organizer.departments.edit",
    component: OrganizerDepartmentEditView,
    beforeEnter: ensureOrganizerDepartmentSession,
  },
  {
    path: "/organizer/branding",
    name: "organizer.branding",
    component: OrganizationBrandingView,
    props: organizationBrandingRouteProps,
    beforeEnter: ensureOrganizerDepartmentSession,
  },
  {
    path: "/organizer/documents",
    name: "organizer.documents.index",
    component: DocumentLibraryView,
    beforeEnter: ensureOrganizerDepartmentSession,
  },
  {
    path: "/organizer/documents/:artifactKind/create",
    name: "organizer.documents.create",
    component: DocumentEditView,
    beforeEnter: ensureOrganizerDepartmentSession,
  },
  {
    path: "/organizer/documents/:artifactKind/:artifactId/edit",
    name: "organizer.documents.edit",
    component: DocumentEditView,
    beforeEnter: ensureOrganizerDepartmentSession,
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

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
