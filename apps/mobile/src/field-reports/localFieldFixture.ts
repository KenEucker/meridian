// Well-known local QA identities shared with the server fixture
// (`php artisan meridian:seed-local-field-fixture`).

export const LOCAL_FIELD_FIXTURE = {
  eventId: "11111111-1111-4111-8111-111111111111",
  eventLabel: "Local Field Event",
  submittedByUserId: "22222222-2222-4222-8222-222222222222",
  staffId: "33333333-3333-4333-8333-333333333333",
  originDeviceId: "44444444-4444-4444-8444-444444444444",
  originNodeId: "55555555-5555-4555-8555-555555555555",
  departmentId: null,
  departmentLabel: null,
  teamId: null,
  teamLabel: null,
} as const;
