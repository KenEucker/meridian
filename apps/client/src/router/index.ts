import { createRouter, createWebHistory, type RouteRecordRaw } from "vue-router";

import {
  installDevelopmentFieldSession,
  resolveFieldSession,
} from "@/field-reports/fieldSession";
import AboutView from "@/views/AboutView.vue";
import FieldReportCreateView from "@/views/FieldReportCreateView.vue";
import FieldReportDetailView from "@/views/FieldReportDetailView.vue";
import FieldReportsIndexView from "@/views/FieldReportsIndexView.vue";
import HomeView from "@/views/HomeView.vue";
import NotFoundView from "@/views/NotFoundView.vue";
import ReadinessView from "@/views/ReadinessView.vue";
import ShiftBoardCurrentView from "@/views/ShiftBoardCurrentView.vue";

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
    path: "/events/:eventId/departments/:departmentId/shift-board/current",
    name: "events.departments.shift-board.current",
    component: ShiftBoardCurrentView,
    props: { surface: "current" },
  },
  {
    path: "/events/:eventId/departments/:departmentId/shift-board/logistics",
    name: "events.departments.shift-board.logistics",
    component: ShiftBoardCurrentView,
    props: { surface: "logistics" },
  },
  {
    path: "/events/:eventId/departments/:departmentId/shift-board/operations",
    name: "events.departments.shift-board.operations",
    component: ShiftBoardCurrentView,
    props: { surface: "operations" },
  },
  {
    path: "/events/:eventId/departments/:departmentId/shift-board/planning",
    name: "events.departments.shift-board.planning",
    component: ShiftBoardCurrentView,
    props: { surface: "planning" },
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
    path: "/:pathMatch(.*)*",
    name: "not-found",
    component: NotFoundView,
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
