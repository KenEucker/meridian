<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import {
  connectionRequiredMessage,
  nodeDidNotAnswer,
} from "@/offline/connectionRequired";
import {
  getMarketingSurface,
  submitOrganizationInterest,
  type MarketingSurfaceAvailability,
} from "@/marketing/marketingModel";

/**
 * `public.marketing` — Meridian describing itself (M18.23; PUBLIC-001 through
 * PUBLIC-006).
 *
 * The one surface in this client that is about Meridian rather than about an
 * organization. Everything else here belongs to somebody: a department, an
 * event, a person's own record. This page is for a visitor who belongs to
 * nothing and is deciding whether to.
 *
 * Three properties follow from that, and the third is the one worth being
 * careful about:
 *
 *  1. **No session, and none expected.** Nothing reads `sessionAccess`, the
 *     permission set, or the selected organization. The visitor has none.
 *  2. **Meridian's identity, and no organization's** (PUBLIC-001, BRAND-003).
 *     Unlike the participation surface, which deliberately wears an
 *     organization's colours, this one must not: there is no organization in
 *     the request, and the page exists precisely for people who belong to none.
 *  3. **The node decides whether it renders at all.** PUBLIC-006 keeps the
 *     surface off an on-site node and off any node locked to an event, and only
 *     the node knows which it is. So the page asks first and sends the visitor
 *     to sign in when the answer is no, rather than advertising a platform on a
 *     laptop that is running somebody's event.
 *
 * The feature tour, its screenshots of the seeded example organization, and the
 * descriptions of the three platform offerings (PUBLIC-007 through PUBLIC-009)
 * are Milestone 20's, and are deliberately absent rather than stubbed.
 */
const emit = defineEmits<{ (event: "unavailable"): void }>();

const surface = ref<MarketingSurfaceAvailability | null>(null);
const loading = ref(true);
/** Whether nothing answered, as opposed to a node saying it does not serve this. */
const unreachable = ref(false);

const organizationName = ref("");
const contactName = ref("");
const contactEmail = ref("");
const description = ref("");

/*
 * The hidden field (PUBLIC-005). A real visitor never reaches it — it is out of
 * the tab order, out of the accessibility tree, and off-screen — so anything in
 * it came from something filling the form in without looking at it. Automated
 * submission is deterred without asking anybody to solve anything.
 */
const trapValue = ref("");

const submitting = ref(false);
const submitted = ref(false);
const submitError = ref<string | null>(null);

const canSubmit = computed(
  () =>
    !submitting.value &&
    surface.value !== null &&
    organizationName.value.trim() !== "" &&
    contactName.value.trim() !== "" &&
    contactEmail.value.trim() !== "" &&
    description.value.trim() !== "",
);

async function load(): Promise<void> {
  loading.value = true;
  unreachable.value = false;

  try {
    surface.value = await getMarketingSurface();
  } catch (error) {
    surface.value = null;

    /*
     * Two failures, and until M18.53 they were the same failure (CLIENT-001,
     * UI-020).
     *
     * A node that does not serve the surface answers 404, and there is nothing
     * to say about it: the page is not there, and whoever mounted this decides
     * where the visitor goes instead. A node that does not answer at all is a
     * different fact, and treating it as the first left this page rendering
     * *nothing* — the template has no branch for a null surface, so a visitor
     * out of coverage at `/platform` got a blank screen and no reason for it.
     * A blank page is the one thing an offline surface may not be.
     */
    if (nodeDidNotAnswer(error)) {
      unreachable.value = true;

      return;
    }

    emit("unavailable");
  } finally {
    loading.value = false;
  }
}

async function submit(): Promise<void> {
  const available = surface.value;

  if (available === null) {
    return;
  }

  submitting.value = true;
  submitError.value = null;

  try {
    await submitOrganizationInterest(
      {
        organizationName: organizationName.value,
        contactName: contactName.value,
        contactEmail: contactEmail.value,
        description: description.value,
      },
      available.formToken,
      trapValue.value,
    );

    submitted.value = true;
  } catch (error) {
    submitError.value = meridianErrorMessage(
      error,
      "That could not be sent. Try again in a little while.",
    );

    // A refusal may be a form token that has aged out, so a fresh one is
    // fetched before the visitor presses the button again. Nothing they typed
    // is touched — the whole point of answering honestly rather than silently
    // is that their message survives the refusal.
    try {
      surface.value = await getMarketingSurface();
    } catch {
      // Leave the existing token in place; the message above already says the
      // submission did not land.
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  void load();
});
</script>

<template>
  <main class="marketing" aria-labelledby="marketing-heading">
    <p v-if="loading" class="marketing__notice" role="status">Loading…</p>

    <p v-else-if="unreachable" class="marketing__notice" role="status">
      {{
        connectionRequiredMessage(
          "This page",
          "whether a Meridian node offers it at all is the node's own answer, and this one gave none",
        )
      }}
      <button type="button" @click="load">Try again</button>
    </p>

    <template v-else-if="surface !== null">
      <header class="marketing__masthead">
        <span class="marketing__mark" aria-hidden="true">M</span>
        <span class="marketing__product">Meridian</span>
      </header>

      <h1 id="marketing-heading" class="marketing__heading">
        Run your event's volunteer operations in one place
      </h1>
      <p class="marketing__lede">
        Meridian is the operations system for organizations that staff events
        with volunteers. Intake and applications, departments and teams, shifts
        and signup, check-in and hours, equipment, policies, and incident
        command all live in one place, so the people running an event are
        working from the same record rather than from four spreadsheets and a
        group chat.
      </p>

      <section class="marketing__section" aria-labelledby="marketing-who">
        <h2 id="marketing-who">Who it is for</h2>
        <p>
          Organizations that put on events staffed by volunteers: festivals and
          gatherings, community organizations, mutual aid and safety crews, and
          the departments inside them — rangers, gate, logistics, medical,
          dispatch, and whoever else your event calls them.
        </p>
      </section>

      <section class="marketing__section" aria-labelledby="marketing-field">
        <h2 id="marketing-field">Built for the field, not just the office</h2>
        <p>
          Events happen where connectivity is worst. Meridian runs on a node you
          can take to the site, keeps working while it is offline, and syncs
          back when it is not. It is free and open source, and you can host it
          yourself.
        </p>
      </section>

      <section
        v-if="submitted"
        class="marketing__section"
        aria-labelledby="marketing-thanks"
      >
        <h2 id="marketing-thanks">Thank you</h2>
        <p>
          We have your note about {{ organizationName }}. Somebody who runs this
          Meridian deployment will read it and get back to you at the address
          you gave us.
        </p>
        <p class="marketing__quiet">
          Nothing has been created yet — no account, no organization, and no
          sign-up. Setting an organization up on Meridian is a deliberate step
          somebody takes with you, once you have both decided it is a fit.
        </p>
      </section>

      <section
        v-else
        class="marketing__section"
        aria-labelledby="marketing-interest"
      >
        <h2 id="marketing-interest">Tell us about your organization</h2>
        <p class="marketing__quiet">
          Writing in creates nothing and signs you up for nothing. It reaches
          the people who run this deployment, and they will get back to you.
        </p>

        <p v-if="submitError" class="marketing__error" role="alert">
          {{ submitError }}
        </p>

        <form class="marketing__form" @submit.prevent="submit">
          <label class="marketing__field">
            <span>Organization name</span>
            <input
              v-model="organizationName"
              type="text"
              required
              maxlength="255"
              autocomplete="organization"
            />
          </label>

          <label class="marketing__field">
            <span>Your name</span>
            <input
              v-model="contactName"
              type="text"
              required
              maxlength="255"
              autocomplete="name"
            />
          </label>

          <label class="marketing__field">
            <span>Your email address</span>
            <input
              v-model="contactEmail"
              type="email"
              required
              maxlength="255"
              autocomplete="email"
            />
          </label>

          <label class="marketing__field">
            <span>What does your organization run?</span>
            <textarea
              v-model="description"
              rows="6"
              required
              maxlength="5000"
              aria-describedby="marketing-description-help"
            ></textarea>
          </label>
          <p id="marketing-description-help" class="marketing__quiet">
            Your events, roughly how many volunteers you staff, and what you are
            hoping Meridian would take off your hands. A couple of sentences is
            plenty.
          </p>

          <!--
            PUBLIC-005: out of the tab order, out of the accessibility tree, and
            named so no autofill heuristic recognises it. A visitor who somehow
            reaches it is told to leave it alone.
          -->
          <div class="marketing__trap" aria-hidden="true">
            <label>
              <span>Leave this field empty</span>
              <input
                v-model="trapValue"
                type="text"
                tabindex="-1"
                autocomplete="off"
              />
            </label>
          </div>

          <button type="submit" :disabled="!canSubmit">
            {{ submitting ? "Sending…" : "Send" }}
          </button>
        </form>
      </section>

      <section class="marketing__section" aria-labelledby="marketing-already">
        <h2 id="marketing-already">Already working an event?</h2>
        <p>
          <RouterLink :to="{ name: 'login' }">Sign in</RouterLink> if your
          organization already uses this deployment.
        </p>
      </section>
    </template>
  </main>
</template>

<style scoped>
.marketing {
  max-width: 42rem;
  margin: 0 auto;
  padding: var(--m-space-6, 1.5rem);
  display: flex;
  flex-direction: column;
  gap: var(--m-space-4, 1rem);
}

.marketing__masthead {
  display: flex;
  align-items: center;
  gap: var(--m-space-3, 0.75rem);
}

.marketing__mark {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: var(--m-radius-2, 0.375rem);
  background: var(--m-color-primary, #2f5d50);
  color: var(--m-color-surface, #ffffff);
  display: grid;
  place-items: center;
  font-weight: 700;
}

.marketing__product {
  font-weight: 600;
  font-size: 1.15rem;
}

.marketing__heading {
  margin: 0;
  font-size: 1.6rem;
  line-height: 1.25;
}

.marketing__lede,
.marketing__section p {
  margin: 0;
}

.marketing__quiet {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.925rem;
}

.marketing__section {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.marketing__section h2 {
  margin: 0;
  font-size: 1.15rem;
}

.marketing__notice,
.marketing__error {
  margin: 0;
  padding: var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}

.marketing__error {
  border-color: var(--m-color-danger, #a3352b);
}

.marketing__form {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3, 0.75rem);
}

.marketing__field {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-1, 0.25rem);
  font-weight: 600;
  font-size: 0.925rem;
}

.marketing__field input,
.marketing__field textarea {
  font: inherit;
  font-weight: 400;
  padding: var(--m-space-2, 0.5rem);
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}

.marketing__field textarea {
  resize: vertical;
}

/*
 * Taken out of the layout rather than hidden with `display: none`, which some
 * automated clients check for and skip.
 */
.marketing__trap {
  position: absolute;
  left: -9999px;
  width: 0;
  height: 0;
  overflow: hidden;
}
</style>
