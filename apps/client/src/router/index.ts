import { createRouter, createWebHistory, type RouteRecordRaw } from "vue-router";

import {
  installDevelopmentFieldSession,
  resolveFieldSession,
} from "@/field-reports/fieldSession";
import AboutView from "@/views/AboutView.vue";
import DepartmentOverviewView from "@/views/DepartmentOverviewView.vue";
import FieldReportCreateView from "@/views/FieldReportCreateView.vue";
import FieldReportDetailView from "@/views/FieldReportDetailView.vue";
import FieldReportsIndexView from "@/views/FieldReportsIndexView.vue";
import EventInfoView from "@/views/EventInfoView.vue";
import HomeView from "@/views/HomeView.vue";
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
import OrganizerDepartmentEditView from "@/views/OrganizerDepartmentEditView.vue";
import OrganizerDepartmentListView from "@/views/OrganizerDepartmentListView.vue";
import OrganizerStaffView from "@/views/OrganizerStaffView.vue";
import DepartmentTeamEditView from "@/views/DepartmentTeamEditView.vue";
import DepartmentTeamsListView from "@/views/DepartmentTeamsListView.vue";
import PlanningTableView from "@/views/PlanningTableView.vue";
import ReadinessView from "@/views/ReadinessView.vue";
import { selectFixtureDepartment } from "@/department-teams/fixtureDepartmentAccess";
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
 */
function ensureDepartmentSelfAdminSession(to: {
  params: Record<string, string | string[]>;
}): void {
  if (typeof to.params.departmentId === "string") {
    selectFixtureDepartment(to.params.departmentId);
  }

  if (!resolveDepartmentSelfAdminSession()) {
    installDevelopmentDepartmentSelfAdminSession();
  }
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
    path: "/:pathMatch(.*)*",
    name: "not-found",
    component: NotFoundView,
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
