<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import {
  dismissProfileChangeRequest,
  getMyProfile,
  profileDisplayName,
  removeProfilePicture,
  requestHandleChange,
  submitProfilePicture,
  updateMyProfile,
  withdrawProfileChangeRequest,
  type MyStaffProfile,
  type ProfileChangeRequest,
} from "@/staff-profile/myProfileModel";

/**
 * Edit your own profile (M18.20; VOL-014 through VOL-016; UI contract 12.3
 * `staff.profile-edit`).
 *
 * The form is split the way the requirement is split. Preferred name, phone,
 * and city/state are yours to change and apply the moment they save (VOL-015).
 * Legal name, email, and date of birth are on the page and not in the form:
 * they are identity and eligibility data, the server refuses them as well as
 * this page omitting them, and the page says whom to ask instead (VOL-016).
 * The handle is shown the same way — its change path is a reviewed request
 * that arrives with a later task, not this form.
 *
 * Which fields are editable comes from the read (`self_editable_fields`), so
 * this page renders the node's boundary rather than a copy of it (CLIENT-006).
 */
const router = useRouter();

const profiles = ref<readonly MyStaffProfile[]>([]);
const selectedId = ref<string | null>(null);
const loadError = ref<string | null>(null);
const loading = ref(false);

const profile = computed(
  () => profiles.value.find((entry) => entry.id === selectedId.value) ?? null,
);

const draft = reactive({
  preferredName: "",
  phone: "",
  city: "",
  state: "",
});
const formError = ref<string | null>(null);
const saved = ref(false);
const busy = ref(false);

async function loadProfile(): Promise<void> {
  loading.value = true;
  loadError.value = null;

  try {
    profiles.value = (await getMyProfile()).profiles;
    selectedId.value =
      profiles.value.find((entry) => entry.id === selectedId.value)?.id ??
      profiles.value[0]?.id ??
      null;
  } catch (error) {
    profiles.value = [];
    selectedId.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to read your profile. Check the connection to this node and try again.",
    );
  } finally {
    loading.value = false;
  }
}

void loadProfile();

watch(
  profile,
  (current) => {
    draft.preferredName = current?.preferredName ?? "";
    draft.phone = current?.phone ?? "";
    draft.city = current?.city ?? "";
    draft.state = current?.state ?? "";
  },
  { immediate: true },
);

function editable(field: string): boolean {
  return profile.value?.selfEditableFields.includes(field) ?? false;
}

async function onSubmit(): Promise<void> {
  const current = profile.value;

  if (current === null) {
    return;
  }

  formError.value = null;
  saved.value = false;
  busy.value = true;

  try {
    const updated = await updateMyProfile({
      staffId: current.id,
      preferredName: draft.preferredName.trim() || null,
      phone: draft.phone.trim() || null,
      city: draft.city.trim() || null,
      state: draft.state.trim() || null,
    });

    if (updated !== null) {
      profiles.value = profiles.value.map((entry) =>
        entry.id === updated.id ? updated : entry,
      );
    }

    saved.value = true;
  } catch (error) {
    formError.value = meridianErrorMessage(
      error,
      "Unable to save your profile. Check the connection to this node and try again.",
    );
  } finally {
    busy.value = false;
  }
}

async function onDone(): Promise<void> {
  await router.push({ name: "staff.me" });
}

/*
 * The picture and handle paths (M18.20B, M18.20C; VOL-017, VOL-021 through
 * VOL-024).
 *
 * Both share one state pair with the form above deliberately kept separate:
 * saving text and submitting a picture are different actions with different
 * outcomes, and a "Saved" line under a picture that is actually awaiting
 * review would be the one message this surface must not print.
 */
const pictureError = ref<string | null>(null);
const pictureNotice = ref<string | null>(null);
const pictureBusy = ref(false);

/**
 * A request still waiting on somebody, as against one already decided
 * (VOL-024, VOL-029).
 *
 * The surface treats the two differently: a pending request offers Withdraw
 * and blocks a second submission, while a decided one is a notice to read and
 * clear, and does not stand in the way of trying again.
 */
function pendingOnly(
  request: ProfileChangeRequest | null,
): ProfileChangeRequest | null {
  return request?.status === "pending" ? request : null;
}

/** A decision the staff member has not cleared yet (VOL-029). */
function decidedOnly(
  request: ProfileChangeRequest | null,
): ProfileChangeRequest | null {
  return request !== null && request.status !== "pending" ? request : null;
}

const pendingPicture = computed(() =>
  pendingOnly(profile.value?.latestPictureRequest ?? null),
);
const decidedPicture = computed(() =>
  decidedOnly(profile.value?.latestPictureRequest ?? null),
);
const pendingHandle = computed(() =>
  pendingOnly(profile.value?.latestHandleRequest ?? null),
);
const decidedHandle = computed(() =>
  decidedOnly(profile.value?.latestHandleRequest ?? null),
);

/**
 * What a decided request should say to the person who made it.
 *
 * A rejection carries the reviewer's reason, because that is the whole point
 * of showing it; the other two states are stated plainly and briefly, since
 * nothing is being asked of the reader.
 */
function decisionNotice(request: ProfileChangeRequest, noun: string): string {
  if (request.status === "rejected") {
    const reason = request.decisionReason;

    return reason === null || reason === ""
      ? `Your ${noun} was not approved.`
      : `Your ${noun} was not approved: ${reason}`;
  }

  return request.status === "approved"
    ? `Your ${noun} was approved.`
    : `You withdrew your ${noun}.`;
}

async function onPictureChosen(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0] ?? null;
  const current = profile.value;

  if (file === null || current === null) {
    return;
  }

  pictureError.value = null;
  pictureNotice.value = null;
  pictureBusy.value = true;

  try {
    const result = await submitProfilePicture(current.id, file);
    await loadProfile();
    // The node decided whether this applied or is waiting; the page reports
    // its answer rather than predicting one from the policy it last read.
    pictureNotice.value =
      result?.status === "pending"
        ? "Submitted. Your current picture stays in place until an organizer reviews this one."
        : "Your new picture is on your record.";
  } catch (error) {
    pictureError.value = meridianErrorMessage(
      error,
      "Unable to submit that picture. Check the connection to this node and try again.",
    );
  } finally {
    pictureBusy.value = false;
    // Clear the input so choosing the same file again still fires a change.
    input.value = "";
  }
}

async function onWithdrawPicture(): Promise<void> {
  const pending = pendingPicture.value;

  if (pending === null) {
    return;
  }

  pictureError.value = null;
  pictureNotice.value = null;
  pictureBusy.value = true;

  try {
    await withdrawProfileChangeRequest(pending.id);
    await loadProfile();
    pictureNotice.value = "Withdrawn. The submitted picture was discarded.";
  } catch (error) {
    pictureError.value = meridianErrorMessage(
      error,
      "Unable to withdraw that submission. Check the connection to this node and try again.",
    );
  } finally {
    pictureBusy.value = false;
  }
}

async function onRemovePicture(): Promise<void> {
  const current = profile.value;

  if (current === null) {
    return;
  }

  pictureError.value = null;
  pictureNotice.value = null;
  pictureBusy.value = true;

  try {
    await removeProfilePicture(current.id);
    await loadProfile();
    pictureNotice.value = "Removed. You no longer have a profile picture.";
  } catch (error) {
    pictureError.value = meridianErrorMessage(
      error,
      "Unable to remove your picture. Check the connection to this node and try again.",
    );
  } finally {
    pictureBusy.value = false;
  }
}

/** Clear a decision from the surface once it has been read (VOL-029). */
async function onDismiss(
  request: ProfileChangeRequest,
  busy: { value: boolean },
  error: { value: string | null },
): Promise<void> {
  error.value = null;
  busy.value = true;

  try {
    await dismissProfileChangeRequest(request.id);
    await loadProfile();
  } catch (caught) {
    error.value = meridianErrorMessage(
      caught,
      "Unable to clear that. Check the connection to this node and try again.",
    );
  } finally {
    busy.value = false;
  }
}

async function onDismissPicture(): Promise<void> {
  const decided = decidedPicture.value;

  if (decided !== null) {
    pictureNotice.value = null;
    await onDismiss(decided, pictureBusy, pictureError);
  }
}

async function onDismissHandle(): Promise<void> {
  const decided = decidedHandle.value;

  if (decided !== null) {
    handleNotice.value = null;
    await onDismiss(decided, handleBusy, handleError);
  }
}

const handleDraft = ref("");
const handleError = ref<string | null>(null);
const handleNotice = ref<string | null>(null);
const handleBusy = ref(false);

watch(
  profile,
  (current) => {
    handleDraft.value = current?.handle ?? "";
  },
  { immediate: true },
);

/**
 * What the next handle change will do, in the words the person needs before
 * they make it (VOL-017, VOL-027, VOL-028).
 *
 * Written from the node's policy and count rather than from a rule compiled
 * into this client, so an organization that reviews everything and one that
 * reviews nothing each get an accurate sentence.
 */
const handleAllowanceNote = computed(() => {
  const current = profile.value;

  if (current === null) {
    return "";
  }

  const hasHandle = current.handle !== null && current.handle !== "";

  if (!hasHandle) {
    return current.handleChangePolicy === "auto_approved" ||
      current.handleChangePolicy === "staff_sets_first"
      ? "Setting your first handle takes effect immediately and does not count as a change."
      : "Your first handle goes to an organizer to approve.";
  }

  const remaining = current.remainingSelfServiceHandleChanges;

  if (remaining === 0) {
    return current.handleChangePolicy === "auto_approved"
      ? "You have used all of your direct handle changes. Another one goes to an organizer for review."
      : "Handle changes are reviewed by an organizer.";
  }

  return remaining === 1
    ? "One more handle change takes effect immediately. After that, changes are reviewed."
    : `${remaining} handle changes take effect immediately. After that, changes are reviewed.`;
});

/** Whether the next handle change applies outright or goes for review. */
const handleAppliesImmediately = computed(() => {
  const current = profile.value;

  if (current === null) {
    return false;
  }

  const hasHandle = current.handle !== null && current.handle !== "";

  if (!hasHandle) {
    return (
      current.handleChangePolicy === "auto_approved" ||
      current.handleChangePolicy === "staff_sets_first"
    );
  }

  return current.remainingSelfServiceHandleChanges > 0;
});

/** What the picture control should say it will do (VOL-027). */
const pictureSubmitNote = computed(() => {
  const current = profile.value;

  if (current === null) {
    return "";
  }

  if (current.profilePictureChangePolicy === "organizer_sets_first" &&
    current.profilePictureUrl === null) {
    return "Your organization sets first profile pictures. Ask an organizer to add yours, and you can submit a replacement after that.";
  }

  if (!current.canSubmitPicture) {
    return "You can submit a picture once you are an active staff member in an organization.";
  }

  const appliesNow =
    current.profilePictureChangePolicy === "auto_approved" ||
    (current.profilePictureChangePolicy === "staff_sets_first" &&
      current.profilePictureUrl === null);

  return appliesNow
    ? "JPEG, PNG, or WebP, up to 10 MB. Your picture takes effect as soon as it uploads."
    : "JPEG, PNG, or WebP, up to 10 MB. A new picture is reviewed before it replaces the one on your record. Removing yours needs no review.";
});

async function onSubmitHandle(): Promise<void> {
  const current = profile.value;
  const handle = handleDraft.value.trim();

  if (current === null || handle === "") {
    return;
  }

  handleError.value = null;
  handleNotice.value = null;
  handleBusy.value = true;

  try {
    const result = await requestHandleChange(current.id, handle);
    await loadProfile();
    // The node decided which of the two happened; this reports its answer
    // rather than predicting one from the count it was last shown.
    handleNotice.value =
      result?.status === "pending"
        ? "Submitted for review. Your handle stays as it is until an organizer decides."
        : "Handle changed. This took effect immediately.";
  } catch (error) {
    handleError.value = meridianErrorMessage(
      error,
      "Unable to change your handle. Check the connection to this node and try again.",
    );
  } finally {
    handleBusy.value = false;
  }
}

async function onWithdrawHandle(): Promise<void> {
  const pending = pendingHandle.value;

  if (pending === null) {
    return;
  }

  handleError.value = null;
  handleNotice.value = null;
  handleBusy.value = true;

  try {
    await withdrawProfileChangeRequest(pending.id);
    await loadProfile();
    handleNotice.value = "Withdrawn. Your handle is unchanged.";
  } catch (error) {
    handleError.value = meridianErrorMessage(
      error,
      "Unable to withdraw that request. Check the connection to this node and try again.",
    );
  } finally {
    handleBusy.value = false;
  }
}
</script>

<template>
  <section class="profile-edit" aria-labelledby="profile-edit-heading">
    <p class="profile-edit__nav">
      <RouterLink :to="{ name: 'staff.me' }">Back To Me</RouterLink>
    </p>

    <p class="profile-edit__eyebrow">Staff profile</p>
    <h1 id="profile-edit-heading" class="profile-edit__heading">Edit profile</h1>

    <p v-if="loadError" class="profile-edit__restricted" role="alert">
      {{ loadError }}
      <button type="button" @click="loadProfile()">Try again</button>
    </p>

    <p v-else-if="loading" class="profile-edit__restricted" role="status">
      Reading your profile…
    </p>

    <p v-else-if="profile === null" class="profile-edit__restricted" role="status">
      This login is not linked to a staff profile, so there is nothing to edit
      here.
    </p>

    <template v-else>
      <!--
        A login can speak for more than one staff record. Naming which record
        the form is editing is only worth screen space when there is a choice.
      -->
      <label v-if="profiles.length > 1" class="profile-edit__field">
        Which staff record
        <select v-model="selectedId">
          <option v-for="entry in profiles" :key="entry.id" :value="entry.id">
            {{ profileDisplayName(entry) }} — {{ entry.email }}
          </option>
        </select>
      </label>

      <p v-if="formError" class="profile-edit__error" role="alert">
        {{ formError }}
      </p>
      <p v-else-if="saved" class="profile-edit__saved" role="status">
        Saved. These changes take effect immediately.
      </p>

      <form class="profile-edit__form" @submit.prevent="onSubmit">
        <fieldset class="profile-edit__group">
          <legend>Yours to change</legend>
          <p class="profile-edit__hint">
            These apply as soon as they save, with no review.
          </p>

          <label v-if="editable('preferred_name')" class="profile-edit__field">
            Preferred name
            <input
              v-model="draft.preferredName"
              type="text"
              autocomplete="nickname"
            />
          </label>
          <label v-if="editable('phone')" class="profile-edit__field">
            Phone
            <input v-model="draft.phone" type="tel" autocomplete="tel" />
          </label>
          <div class="profile-edit__row">
            <label v-if="editable('city')" class="profile-edit__field">
              City
              <input
                v-model="draft.city"
                type="text"
                autocomplete="address-level2"
              />
            </label>
            <label v-if="editable('state')" class="profile-edit__field">
              State
              <input
                v-model="draft.state"
                type="text"
                autocomplete="address-level1"
              />
            </label>
          </div>
        </fieldset>

        <fieldset class="profile-edit__group">
          <legend>On your record, changed with help</legend>
          <p class="profile-edit__hint">
            Legal name and email identify you to the organization and to
            sign-in, and date of birth governs event age eligibility. Ask an
            organizer to change any of them.
          </p>

          <dl class="profile-edit__readonly">
            <div>
              <dt>Legal name</dt>
              <dd>{{ profile.legalName }}</dd>
            </div>
            <div>
              <dt>Email</dt>
              <dd>{{ profile.email }}</dd>
            </div>
            <div>
              <dt>Date of birth</dt>
              <dd>{{ profile.dateOfBirth ?? "Not on record" }}</dd>
            </div>
            <div>
              <dt>Emergency contact</dt>
              <dd>
                {{
                  profile.emergencyContactName === null
                    ? "Not on record"
                    : `${profile.emergencyContactName} — ${profile.emergencyContactPhone ?? "no phone"}`
                }}
              </dd>
            </div>
          </dl>
        </fieldset>

        <div class="profile-edit__actions">
          <button type="submit" :disabled="busy">Save</button>
          <button type="button" :disabled="busy" @click="onDone">Done</button>
        </div>
      </form>

      <!--
        Your picture (M18.20C; VOL-013, VOL-021 through VOL-023).

        Outside the form above because it does not save with it: a submission
        is its own action with its own outcome, and that outcome is "waiting
        for review" rather than "saved". The current picture stays on screen
        and in force the whole time a submission is pending, which is the
        property VOL-021 is about — the person keeps the picture everybody
        knows them by until somebody decides on the new one.
      -->
      <section class="profile-edit__group" aria-labelledby="profile-picture-heading">
        <h2 id="profile-picture-heading" class="profile-edit__legend">
          Your picture
        </h2>

        <p v-if="pictureError" class="profile-edit__error" role="alert">
          {{ pictureError }}
        </p>
        <p v-else-if="pictureNotice" class="profile-edit__saved" role="status">
          {{ pictureNotice }}
        </p>

        <!--
          A decision that has not been cleared yet (VOL-029). A rejection
          carries the reviewer's reason, which is the whole reason to show it;
          Clear dismisses the notice without touching the record behind it.
        -->
        <div
          v-if="decidedPicture"
          class="profile-edit__decision"
          :data-status="decidedPicture.status"
          role="status"
        >
          <p>{{ decisionNotice(decidedPicture, "profile picture") }}</p>
          <button type="button" :disabled="pictureBusy" @click="onDismissPicture">
            Clear
          </button>
        </div>

        <div class="profile-edit__pictures">
          <figure class="profile-edit__picture">
            <img
              v-if="profile.profilePictureUrl"
              :src="profile.profilePictureUrl"
              :alt="`${profileDisplayName(profile)} current profile picture`"
            />
            <div
              v-else
              class="profile-edit__picture-none"
              role="img"
              aria-label="No profile picture on record"
            >
              <span aria-hidden="true">No picture</span>
            </div>
            <figcaption>On your record now</figcaption>
          </figure>

          <figure v-if="pendingPicture" class="profile-edit__picture">
            <img
              v-if="pendingPicture.submittedPictureUrl"
              :src="pendingPicture.submittedPictureUrl"
              alt="The profile picture you submitted, awaiting review"
            />
            <div
              v-else
              class="profile-edit__picture-none"
              role="img"
              aria-label="Submitted picture is not available to display"
            >
              <span aria-hidden="true">Submitted</span>
            </div>
            <figcaption>Waiting for review</figcaption>
          </figure>
        </div>

        <p v-if="pendingPicture" class="profile-edit__hint">
          An organizer or Staff Coordinator reviews this. Until they do, the
          picture on your record is the one above, and only you and your
          reviewers can see the one you submitted.
        </p>
        <p v-else class="profile-edit__hint">{{ pictureSubmitNote }}</p>

        <div class="profile-edit__actions">
          <template v-if="pendingPicture">
            <button
              type="button"
              :disabled="pictureBusy"
              @click="onWithdrawPicture"
            >
              Withdraw submission
            </button>
          </template>
          <template v-else-if="profile.canSubmitPicture">
            <label class="profile-edit__file">
              <span>{{
                profile.profilePictureUrl ? "Submit a new picture" : "Submit a picture"
              }}</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                :disabled="pictureBusy"
                @change="onPictureChosen"
              />
            </label>
          </template>
          <button
            v-if="profile.profilePictureUrl"
            type="button"
            class="profile-edit__destructive"
            :disabled="pictureBusy"
            @click="onRemovePicture"
          >
            Remove current picture
          </button>
        </div>
      </section>

      <!--
        Your handle (M18.20B; VOL-010, VOL-017, VOL-018).

        The allowance is stated before it is spent, which is the whole of
        VOL-017's last sentence: somebody about to use their second of two
        direct changes should know that is what they are doing.
      -->
      <section class="profile-edit__group" aria-labelledby="profile-handle-heading">
        <h2 id="profile-handle-heading" class="profile-edit__legend">
          Your handle
        </h2>

        <p v-if="handleError" class="profile-edit__error" role="alert">
          {{ handleError }}
        </p>
        <p v-else-if="handleNotice" class="profile-edit__saved" role="status">
          {{ handleNotice }}
        </p>

        <div
          v-if="decidedHandle"
          class="profile-edit__decision"
          :data-status="decidedHandle.status"
          role="status"
        >
          <p>
            {{ decisionNotice(decidedHandle, `request for ${decidedHandle.requestedHandle}`) }}
          </p>
          <button type="button" :disabled="handleBusy" @click="onDismissHandle">
            Clear
          </button>
        </div>

        <template v-if="pendingHandle">
          <p class="profile-edit__hint">
            You asked for
            <strong>{{ pendingHandle.requestedHandle }}</strong
            >. Until an organizer decides, your handle stays
            <strong>{{ profile.handle ?? "unset" }}</strong
            >.
          </p>
          <div class="profile-edit__actions">
            <button
              type="button"
              :disabled="handleBusy"
              @click="onWithdrawHandle"
            >
              Withdraw request
            </button>
          </div>
        </template>

        <template v-else>
          <p class="profile-edit__hint">{{ handleAllowanceNote }}</p>
          <form
            class="profile-edit__form"
            @submit.prevent="onSubmitHandle"
          >
            <label class="profile-edit__field">
              Handle
              <input v-model="handleDraft" type="text" maxlength="255" />
            </label>
            <div class="profile-edit__actions">
              <!--
                The button names the outcome the node's policy will actually
                produce, so nobody presses "Change handle" and gets a review.
              -->
              <button
                type="submit"
                :disabled="handleBusy || handleDraft.trim() === '' || handleDraft.trim() === (profile.handle ?? '')"
              >
                {{ handleAppliesImmediately ? "Change handle" : "Request handle change" }}
              </button>
            </div>
          </form>
        </template>
      </section>
    </template>
  </section>
</template>

<style scoped>
.profile-edit {
  width: min(100%, 36rem);
  display: grid;
  gap: var(--m-space-4);
}

.profile-edit__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.profile-edit__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.profile-edit__eyebrow {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.profile-edit__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.profile-edit__restricted,
.profile-edit__error,
.profile-edit__saved {
  margin: 0;
  padding: var(--m-space-3);
  border-radius: var(--m-radius-sm);
  border: 1px solid var(--m-border-default);
}

.profile-edit__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.profile-edit__saved {
  border-color: color-mix(
    in srgb,
    var(--m-status-success, #4a7c59) 40%,
    var(--m-border-default)
  );
}

.profile-edit__form {
  display: grid;
  gap: var(--m-space-4);
}

.profile-edit__group {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.profile-edit__group legend {
  padding: 0 var(--m-space-1);
  font-weight: 800;
}

.profile-edit__hint {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.profile-edit__field {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.profile-edit__field input,
.profile-edit__field select {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
  font-weight: 400;
}

.profile-edit__row {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--m-space-3);
}

@media (min-width: 30rem) {
  .profile-edit__row {
    grid-template-columns: 2fr 1fr;
  }
}

/*
 * A decision waiting to be read. A rejection is the one somebody has to act
 * on, so it is the one that carries a colour; the rest state themselves and
 * get out of the way.
 */
.profile-edit__decision {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.profile-edit__decision[data-status="rejected"] {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
}

.profile-edit__decision p {
  margin: 0;
  flex: 1 1 14rem;
}

.profile-edit__decision button {
  min-height: 2.25rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.profile-edit__legend {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
  font-weight: 800;
}

/*
 * Current and submitted side by side, so the comparison VOL-021 is about is
 * the one the page makes.
 */
.profile-edit__pictures {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-4);
}

.profile-edit__picture {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  justify-items: center;
}

.profile-edit__picture figcaption {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  text-transform: uppercase;
}

.profile-edit__picture img,
.profile-edit__picture-none {
  width: 7rem;
  aspect-ratio: 1;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  object-fit: cover;
  background: var(--m-surface-base);
  flex: 0 0 auto;
}

/* The file input is the button: a bare input reads as an unlabelled control. */
.profile-edit__file {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font-weight: 600;
  cursor: pointer;
}

.profile-edit__file input {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.profile-edit__file:focus-within {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.profile-edit__destructive {
  border-color: var(--m-action-destructive-bg) !important;
  background: var(--m-action-destructive-bg) !important;
  color: var(--m-action-destructive-text) !important;
}

.profile-edit__picture-none {
  display: grid;
  place-items: center;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  text-transform: uppercase;
}

.profile-edit__readonly {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
}

.profile-edit__readonly div {
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.profile-edit__readonly dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.profile-edit__readonly dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
}

.profile-edit__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.profile-edit__actions button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.profile-edit__actions button[type="submit"] {
  border: 0;
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.profile-edit__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.profile-edit__restricted button {
  margin-left: var(--m-space-2);
  min-height: 2rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}
</style>
