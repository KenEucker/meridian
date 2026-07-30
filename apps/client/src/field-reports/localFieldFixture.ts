// Well-known local QA identities shared with the server fixture
// (`php artisan meridian:seed-local-field-fixture`).

export const LOCAL_FIELD_FIXTURE = {
  eventId: "11111111-1111-4111-8111-111111111111",
  eventLabel: "Local Field Event",
  submittedByUserId: "22222222-2222-4222-8222-222222222222",
  staffId: "33333333-3333-4333-8333-333333333333",
  originDeviceId: "44444444-4444-4444-8444-444444444444",
  originNodeId: "55555555-5555-4555-8555-555555555555",
  departmentId: "66666666-6666-4666-8666-666666666666",
  departmentLabel: "Rangers",
  teamId: "77777777-7777-4777-8777-777777777777",
  teamLabel: "Command",
} as const;

/**
 * The departments the local fixture seeds the signed-in staff member into.
 *
 * Kept here rather than beside either of the two modules that read them, because
 * both do: the local development session document that stands in for `GET
 * /api/me` builds its associations from these, and the fixture data the
 * not-yet-bound department surfaces still read is keyed by the same ids. One of
 * those has to own the constants and neither may import the other, so the
 * shared-identity module that already exists owns them.
 */
export const LOCAL_FIELD_DEPARTMENT_IDS = {
  organizer: "22222222-2222-4222-8222-222222222201",
  rangers: LOCAL_FIELD_FIXTURE.departmentId,
  gate: "22222222-2222-4222-8222-222222222202",
  dpw: "22222222-2222-4222-8222-222222222203",
} as const;

export const LOCAL_FIELD_TEAM_IDS = {
  organizerDefault: "77777777-7777-4777-8777-777777777760",
  rangersDefault: "77777777-7777-4777-8777-777777777770",
  rangersDirt: "77777777-7777-4777-8777-777777777771",
  gateDefault: "77777777-7777-4777-8777-777777777780",
  gateCredentials: "77777777-7777-4777-8777-777777777781",
  dpwDefault: "77777777-7777-4777-8777-777777777790",
  dpwBikes: "77777777-7777-4777-8777-777777777791",
} as const;
