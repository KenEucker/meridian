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
import AboutView from "@/views/AboutView.vue";
import DepartmentOverviewView from "@/views/DepartmentOverviewView.vue";
import DocumentEditView from "@/views/DocumentEditView.vue";
import EventContextView from "@/views/EventContextView.vue";
import DocumentLibraryView from "@/views/DocumentLibraryView.vue";
import FieldReportCreateView from "@/views/FieldReportCreateView.vue";
import FieldReportDetailView from "@/views/FieldReportDetailView.vue";
import FieldReportsIndexView from "@/views/FieldReportsIndexView.vue";
import EventInfoView from "@/views/EventInfoView.vue";
import KioskHomeView from "@/views/KioskHomeView.vue";
import KioskSafeTimeoutView from "@/views/KioskSafeTimeoutView.vue";
import KioskWorkstationLoginView from "@/views/KioskWorkstationLoginView.vue";
import LoginCodeView from "@/views/LoginCodeView.vue";
import LoginView from "@/views/LoginView.vue";
import MarketingView from "@/views/MarketingView.vue";
import { workstationSessionState } from "@/session/workstationSession";
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
import OrganizerApplicationsView from "@/views/OrganizerApplicationsView.vue";
import ParticipationView from "@/views/ParticipationView.vue";
import RootView from "@/views/RootView.vue";
import OrganizerDepartmentEditView from "@/views/OrganizerDepartmentEditView.vue";
import OrganizerDepartmentListView from "@/views/OrganizerDepartmentListView.vue";
import OrganizerConfigurationView from "@/views/OrganizerConfigurationView.vue";
import OrganizerCredentialsView from "@/views/OrganizerCredentialsView.vue";
import OrganizerDocumentAcknowledgmentsView from "@/views/OrganizerDocumentAcknowledgmentsView.vue";
import OrganizerProfileChangeRequestsView from "@/views/OrganizerProfileChangeRequestsView.vue";
import SignupAcknowledgmentView from "@/views/SignupAcknowledgmentView.vue";
import StaffDocumentAcknowledgmentsView from "@/views/StaffDocumentAcknowledgmentsView.vue";
import StaffDocumentDetailView from "@/views/StaffDocumentDetailView.vue";
import StaffDocumentLibraryView from "@/views/StaffDocumentLibraryView.vue";
import OrganizerStaffView from "@/views/OrganizerStaffView.vue";
import DepartmentEquipmentView from "@/views/DepartmentEquipmentView.vue";
import DepartmentExportsView from "@/views/DepartmentExportsView.vue";
import OrganizerExportsView from "@/views/OrganizerExportsView.vue";
import DepartmentShiftEditView from "@/views/DepartmentShiftEditView.vue";
import DepartmentShiftListView from "@/views/DepartmentShiftListView.vue";
import DepartmentTeamEditView from "@/views/DepartmentTeamEditView.vue";
import DepartmentTeamsListView from "@/views/DepartmentTeamsListView.vue";
import DepartmentTrainingDetailView from "@/views/DepartmentTrainingDetailView.vue";
import DepartmentTrainingEditView from "@/views/DepartmentTrainingEditView.vue";
import DepartmentTrainingListView from "@/views/DepartmentTrainingListView.vue";
import PlanningTableView from "@/views/PlanningTableView.vue";
import ReadinessView from "@/views/ReadinessView.vue";
import StaffProfileEditView from "@/views/StaffProfileEditView.vue";
import StaffProfileRequestsView from "@/views/StaffProfileRequestsView.vue";
import StaffShiftBoardView from "@/views/StaffShiftBoardView.vue";
import TeamOverviewView from "@/views/TeamOverviewView.vue";
import WaiverAdministrationView from "@/views/WaiverAdministrationView.vue";
import { selectSessionDepartment } from "@/session/sessionAccess";

/*
 * The Field Report routes installed a development session on entry until M18.9
 * (M9.4), so list, create, and detail stayed exercisable before login existed.
 * Nothing installs one now. The author surfaces resolve their session from the
 * session document, and render their unavailable state when there is none —
 * which is the honest answer for a client nobody has signed in to, and the one a
 * route guard was hiding.
 */

/**
 * Work the department named in the URL (M16.6).
 *
 * The department in the route is also what the client is working in, so it is
 * recorded as the session's department selection. Following the URL rather than
 * the other way round is what makes a deep link land in the right department:
 * the shell, the navigation, and the surface then all read the same selection,
 * and the capabilities that apply are the ones the session response carries
 * for it.
 *
 * It used to install a development department-lead session alongside, which is
 * gone with the rest of the fixtures (M18.9). Nothing is granted here. A
 * department-scoped surface is reachable on the capabilities the session
 * document carries and refused by the node regardless (CLIENT-006).
 */
function selectDepartmentFromRoute(to: {
  params: Record<string, string | string[]>;
}): void {
  if (typeof to.params.departmentId === "string") {
    selectSessionDepartment(to.params.departmentId);
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
  /*
   * The deployment root (M18.23; PUBLIC-001, PUBLIC-006).
   *
   * `RootView` decides which of two surfaces this address is: the public
   * marketing page for a client holding nothing, and the home directory for one
   * holding a session. It is public in the router because the marketing half
   * has to render for somebody with no credential; `RootView` sends a client
   * holding nothing to sign in when the node does not serve the surface.
   */
  {
    path: "/",
    name: "home",
    component: RootView,
  },
  /*
   * The marketing surface at an address of its own, so it is reachable by a
   * signed-in reader who followed a link to it and by anybody who wants to
   * point at it directly.
   */
  {
    path: "/platform",
    name: "public.marketing",
    component: MarketingView,
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
  /*
   * The public participation surfaces (M18.21A; APP-016, APP-017; UI contract
   * 12.1).
   *
   * `/apply/:organizationSlug` is the address an organizer, a department lead,
   * or anybody else hands out. `/apply/:organizationSlug/:eventSlug` is the
   * same page with the event already chosen, for a link shared about one event.
   * Both are the shareable form APP-017 asks for: readable, repeatable, and
   * carrying no token, because the page is public and a token would imply a
   * gate that is not there.
   */
  {
    path: "/apply/:organizationSlug",
    name: "public.participate",
    component: ParticipationView,
  },
  {
    path: "/apply/:organizationSlug/:eventSlug",
    name: "public.apply",
    component: ParticipationView,
  },
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
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/documents",
    name: "events.departments.documents.index",
    component: DocumentLibraryView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/documents/:artifactKind/create",
    name: "events.departments.documents.create",
    component: DocumentEditView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/documents/:artifactKind/:artifactId/edit",
    name: "events.departments.documents.edit",
    component: DocumentEditView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/trainings",
    name: "events.departments.trainings.index",
    component: DepartmentTrainingListView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/trainings/create",
    name: "events.departments.trainings.create",
    component: DepartmentTrainingEditView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/trainings/:trainingId/edit",
    name: "events.departments.trainings.edit",
    component: DepartmentTrainingEditView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/trainings/:trainingId",
    name: "events.departments.trainings.show",
    component: DepartmentTrainingDetailView,
    beforeEnter: selectDepartmentFromRoute,
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
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/shifts/create",
    name: "events.departments.shifts.create",
    component: DepartmentShiftEditView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/shifts/:shiftId/edit",
    name: "events.departments.shifts.edit",
    component: DepartmentShiftEditView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/equipment",
    name: "events.departments.equipment.index",
    component: DepartmentEquipmentView,
    beforeEnter: selectDepartmentFromRoute,
  },
  /*
   * The department-scoped export surface (M18.26; REPORT-014, REPORT-007).
   *
   * Department-scoped in its path because it is department-scoped in its
   * requests: every export run from it names the department in the route, and
   * the node refuses one outside the caller's own scope. The organizer's half
   * is `organizer.exports` below.
   */
  {
    path: "/events/:eventId/departments/:departmentId/exports",
    name: "events.departments.exports.index",
    component: DepartmentExportsView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/branding",
    name: "events.departments.branding",
    component: DepartmentBrandingView,
    props: departmentBrandingRouteProps,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/teams/create",
    name: "events.departments.teams.create",
    component: DepartmentTeamEditView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/teams/:teamId/edit",
    name: "events.departments.teams.edit",
    component: DepartmentTeamEditView,
    beforeEnter: selectDepartmentFromRoute,
  },
  {
    path: "/events/:eventId/departments/:departmentId/teams/:teamId",
    name: "events.departments.teams.show",
    component: TeamOverviewView,
    beforeEnter: selectDepartmentFromRoute,
  },
  legacyShiftBoardRedirect("current"),
  legacyShiftBoardRedirect("logistics"),
  legacyShiftBoardRedirect("operations"),
  legacyShiftBoardRedirect("planning"),
  {
    path: "/staff/me",
    name: "staff.me",
    component: MeView,
  },
  /*
   * Edit your own profile (M18.20; VOL-014 through VOL-016; UI contract 12.3
   * `staff.profile-edit`). Self-scoped like Me above: whose profile it edits
   * is the session's answer, never the route's.
   */
  {
    path: "/staff/me/edit",
    name: "staff.profile.edit",
    component: StaffProfileEditView,
  },
  /*
   * Where your own requests stand (M18.20D; VOL-024, VOL-025, VOL-029; UI
   * contract 12.3 `staff.profile-requests`). Self-scoped for the same reason
   * the two above are: whose requests these are is the session's answer.
   *
   * Under `/staff/me` rather than beside it, because that is what it is about —
   * the requests a person has made against their own record, not a queue of
   * anything. The reviewer's queue is `organizer.profile-change-requests`.
   */
  {
    path: "/staff/me/requests",
    name: "staff.profile.requests",
    component: StaffProfileRequestsView,
  },
  /*
   * The staff shift board (M18.2; SHIFT-018; UI contract 12.3 `staff.shifts`).
   *
   * Not department-scoped in its path, unlike the department shift list it sits
   * beside. A staff member may work more than one department at an event and
   * signs up across all of them from one screen; the event is the session's, and
   * which departments are on the board is the node's answer from the caller's
   * own memberships.
   */
  {
    path: "/staff/shifts",
    name: "staff.shifts.index",
    component: StaffShiftBoardView,
  },
  /*
   * The acknowledgment pair (M18.6; POL-024, POL-046; UI contract 12.1, 12.3).
   *
   * Two routes over one read, and the split is about what somebody is doing
   * rather than about what they may see. `/signup/acknowledgments` is a step in
   * staff signup: the outstanding signup-context documents, open, one task.
   * `/staff/acknowledgments` is the ledger afterwards, every context, answered
   * rows included, because the version you accepted is the part worth keeping
   * (POL-043).
   *
   * Neither is department-scoped and neither is event-scoped. A requirement is
   * asked by an organization or a department of a person, and which ones reach
   * them is the node's answer from their own memberships.
   */
  {
    path: "/signup/acknowledgments",
    name: "signup.documents.acknowledge",
    component: SignupAcknowledgmentView,
  },
  {
    path: "/staff/acknowledgments",
    name: "staff.documents.acknowledgments",
    component: StaffDocumentAcknowledgmentsView,
  },
  /*
   * The staff document library and one document in it (M18.7; POL-006, POL-008
   * through POL-013, POL-055; UI contract 12.3).
   *
   * Neither is department-scoped, for the same reason the shift board is not: an
   * organization-scoped policy is published to a person rather than to one of
   * their departments, and somebody in two departments should not have to know
   * which one a document was scoped to in order to find it. The department
   * library at `events.departments.documents.index` stays where it is — it is
   * the maintainer's workspace, which is a different job on the same records.
   *
   * The type is in the path because policy and procedure are separate resources
   * on the node, not one resource with a flag, and the detail read has to pick
   * the endpoint before it can ask for anything.
   */
  {
    path: "/staff/documents",
    name: "staff.documents.index",
    component: StaffDocumentLibraryView,
  },
  {
    path: "/staff/documents/:documentType/:documentId",
    name: "staff.documents.show",
    component: StaffDocumentDetailView,
  },
  {
    path: "/staff/field-reports",
    name: "staff.field-reports.index",
    component: FieldReportsIndexView,
  },
  {
    path: "/staff/field-reports/create",
    name: "staff.field-reports.create",
    component: FieldReportCreateView,
  },
  {
    path: "/staff/field-reports/:fieldReportId",
    name: "staff.field-reports.show",
    component: FieldReportDetailView,
  },
  {
    path: "/ims/incidents",
    name: "ims.incidents.index",
    component: IncidentListView,
  },
  {
    path: "/ims/field-reports",
    name: "ims.field-reports.index",
    component: ImsFieldReportListView,
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
  },
  {
    path: "/ims/field-reports/:fieldReportId",
    name: "ims.field-reports.show",
    component: ImsFieldReportDetailView,
  },
  {
    path: "/ims/incidents/create",
    name: "ims.incidents.create",
    component: IncidentEditView,
  },
  {
    path: "/ims/incidents/:incidentId/edit",
    name: "ims.incidents.edit",
    component: IncidentEditView,
  },
  {
    path: "/ims/incidents/:incidentId",
    name: "ims.incidents.show",
    component: IncidentEditView,
  },
  {
    path: "/ims/restricted",
    name: "ims.restricted",
    component: ImsRestrictedView,
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
    path: "/organizer/configuration",
    name: "organizer.configuration.index",
    component: OrganizerConfigurationView,
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
  /*
   * Event credential administration (UI contract 12.6). Alpha 1 reaches it for
   * the credential eligibility export entry point (M16.22); revocation lands on
   * the same surface with M18.5.
   */
  {
    path: "/organizer/credentials",
    name: "organizer.credentials.index",
    component: OrganizerCredentialsView,
  },
  /*
   * The organization/event-scoped export surface (M18.26; REPORT-014,
   * REPORT-006, REPORT-015). Not event-scoped in its path despite being
   * event-scoped in its exports: which event this device is working in is the
   * session's answer, the way it is for `organizer.credentials` beside it, and
   * an address naming an event the session did not resolve would be an address
   * that cannot be honored.
   */
  {
    path: "/organizer/exports",
    name: "organizer.exports.index",
    component: OrganizerExportsView,
  },
  /*
   * Acknowledgment requirement administration and review (M18.6; POL-023,
   * POL-046, POL-047; UI contract 12.6). Organization-scoped, because a
   * requirement's scope is an organization or one of its departments and never
   * an event.
   */
  {
    path: "/organizer/acknowledgments",
    name: "organizer.document-acknowledgments.index",
    component: OrganizerDocumentAcknowledgmentsView,
  },
  /*
   * Application review (M18.21A; APP-005, APP-011, APP-019; UI contract 12.6).
   *
   * Organization-scoped rather than event-scoped, because an application may
   * name the organization and no event at all (APP-001), and because approval
   * has always been an organization-level decision (APP-005). Department leads
   * reach the same route for the read-only visibility APP-011 grants them.
   */
  {
    path: "/organizer/applications",
    name: "organizer.applications.index",
    component: OrganizerApplicationsView,
  },
  /*
   * Handle and profile picture change request review (M18.20D; VOL-019 through
   * VOL-022; UI contract 12.6). Organization-scoped and not event-scoped: a
   * handle is a fact about a person's standing with the organization, and it
   * outlives any one event.
   */
  {
    path: "/organizer/profile-change-requests",
    name: "organizer.profile-change-requests.index",
    component: OrganizerProfileChangeRequestsView,
  },
  /*
   * Waiver administration and completion recording (M18.18; WAIVER-001
   * through WAIVER-006, WAIVER-010). One route for every waiver maintainer
   * despite the organizer prefix: authority follows the waiver's scope —
   * organizers, department leads, and team leads each see exactly the scopes
   * they hold, resolved by the node.
   */
  {
    path: "/organizer/waivers",
    name: "organizer.waivers.index",
    component: WaiverAdministrationView,
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
 *
 * The participation surfaces are public in the strongest sense of the word
 * (APP-016): their whole purpose is to be reachable by somebody who has no
 * account and is not going to make one until an organization approves them.
 * Sending an applicant to a sign-in screen would be asking them to already be
 * what they are applying to become.
 */
const PUBLIC_ROUTE_NAMES: readonly string[] = [
  "login",
  "auth.code.entry",
  "public.participate",
  "public.apply",
  /*
   * The marketing surface and the deployment root it lives at (M18.23;
   * PUBLIC-001). Public for the same reason the participation surfaces are:
   * the visitor it is written for has no account and has not decided whether
   * to want one. `home` is public because `RootView` is the marketing surface
   * for a client holding nothing — it sends that client to sign in itself when
   * the node does not serve the surface, which is a decision only the node can
   * make (PUBLIC-006).
   */
  "public.marketing",
  "home",
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
      if (document === null && movesToSignInOnSessionLoss(target.currentRoute.value.name)) {
        void target.replace({ name: "login" });
      }
    },
  );
}

/**
 * Whether losing a session should move a client off the surface it is standing
 * on (AUTH-023).
 *
 * Almost always the same question as `requiresSignIn`, and `home` is the
 * exception. The root is a public route since M18.23 because it is the
 * marketing surface for a client holding nothing (PUBLIC-001) — but somebody
 * whose token was just revoked mid-shift is not that visitor. Left alone they
 * would watch the home directory turn into a page explaining what Meridian is,
 * which answers a question they were not asking and hides the one thing they
 * need, which is the way back in.
 */
function movesToSignInOnSessionLoss(name: unknown): boolean {
  return requiresSignIn(name) || name === "home";
}
