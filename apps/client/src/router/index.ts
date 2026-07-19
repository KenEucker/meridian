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
import HomeView from "@/views/HomeView.vue";
import LogisticsDeskView from "@/views/LogisticsDeskView.vue";
import NotFoundView from "@/views/NotFoundView.vue";
import OperationsCenterView from "@/views/OperationsCenterView.vue";
import PlanningTableView from "@/views/PlanningTableView.vue";
import ReadinessView from "@/views/ReadinessView.vue";

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
  legacyShiftBoardRedirect("current"),
  legacyShiftBoardRedirect("logistics"),
  legacyShiftBoardRedirect("operations"),
  legacyShiftBoardRedirect("planning"),
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
    path: "/:pathMatch(.*)*",
    name: "not-found",
    component: NotFoundView,
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
