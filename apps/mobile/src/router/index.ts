import { createRouter, createWebHistory, type RouteRecordRaw } from "vue-router";

import {
  installDevelopmentFieldSession,
  resolveFieldSession,
} from "@/field-reports/fieldSession";
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
 * exercisable in the field shell (M9.4).
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
    path: "/events/:eventId/departments/:departmentId/shift-board/current",
    name: "events.departments.shift-board.current",
    component: ShiftBoardCurrentView,
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
