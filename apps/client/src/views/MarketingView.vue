<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";
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
import {
  MARKETING_FEATURE_TOUR,
  type MarketingTourSection,
} from "@/marketing/marketingTour";

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
 * Milestone 20 made this the platform landing page (PUBLIC-007 through
 * PUBLIC-009): the feature tour walks each major feature area with a committed
 * screenshot of the seeded Northwood scenario, the offerings section describes
 * the three ways an organization can run Meridian, and both end at the same
 * place — the interest form that was already here. The offerings are
 * descriptive on purpose: there is no price, no checkout, and no signup path,
 * because organization creation stays a deliberate God Mode act (PUBLIC-004).
 *
 * Every screenshot opens larger in an in-page viewer, because a screenshot
 * small enough to sit in a column is too small to actually read an interface
 * in. The viewer is this page's own dialog rather than a new tab: the visitor
 * stays where they were, and Escape or a click puts them back.
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

/** The tour entry open in the screenshot viewer, and who opened it. */
const enlarged = ref<MarketingTourSection | null>(null);
let enlargedOpener: HTMLElement | null = null;

const viewerClose = ref<HTMLButtonElement | null>(null);

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

async function openShot(
  feature: MarketingTourSection,
  event: Event,
): Promise<void> {
  enlargedOpener =
    event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
  enlarged.value = feature;

  // The page behind the dialog holds still while the dialog is up.
  document.documentElement.style.overflow = "hidden";

  await nextTick();
  viewerClose.value?.focus();
}

function closeShot(): void {
  enlarged.value = null;
  document.documentElement.style.overflow = "";

  // Focus goes back to the screenshot that was opened, so a keyboard reader
  // resumes the tour where they left it rather than at the top of the page.
  enlargedOpener?.focus();
  enlargedOpener = null;
}

onBeforeUnmount(() => {
  document.documentElement.style.overflow = "";
});

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

      <section class="marketing__hero" aria-labelledby="marketing-heading">
        <p class="marketing__eyebrow">The volunteer operations platform</p>
        <h1 id="marketing-heading" class="marketing__heading">
          Run your event's volunteer operations in one place
        </h1>
        <p class="marketing__lede">
          Meridian is the operations system for organizations that staff events
          with volunteers. Intake and applications, departments and teams,
          shifts and signup, check-in and hours, equipment, policies, and
          incident command all live in one place, so the people running an
          event are working from the same record rather than from four
          spreadsheets and a group chat.
        </p>

        <p class="marketing__hero-actions">
          <a
            v-if="!submitted"
            class="marketing__cta marketing__cta--primary"
            href="#marketing-interest"
            >Tell us about your organization</a
          >
          <a class="marketing__cta marketing__cta--quiet" href="#marketing-tour"
            >See what it does</a
          >
        </p>

        <ul class="marketing__badges" aria-label="What sets Meridian apart">
          <li>Keeps working offline</li>
          <li>Free and open source</li>
          <li>Runs where your event is</li>
        </ul>
      </section>

      <div class="marketing__pair">
        <section class="marketing__card" aria-labelledby="marketing-who">
          <h2 id="marketing-who">Who it is for</h2>
          <p>
            Organizations that put on events staffed by volunteers: festivals
            and gatherings, community organizations, mutual aid and safety
            crews, and the departments inside them — rangers, gate, logistics,
            medical, dispatch, and whoever else your event calls them.
          </p>
        </section>

        <section class="marketing__card" aria-labelledby="marketing-field">
          <h2 id="marketing-field">Built for the field, not just the office</h2>
          <p>
            Events happen where connectivity is worst. Meridian runs on a node
            you can take to the site, keeps working while it is offline, and
            syncs back when it is not. It is free and open source, and you can
            host it yourself.
          </p>
        </section>
      </div>

      <section class="marketing__tour" aria-labelledby="marketing-tour">
        <header class="marketing__section-head">
          <p class="marketing__eyebrow">Feature tour</p>
          <h2 id="marketing-tour">What Meridian does</h2>
          <p class="marketing__quiet">
            Every screenshot below shows Northwood Collective, the fictional
            example organization Meridian's own development runs against. No
            real organization's data appears on this page. Select any
            screenshot to see more of the interface.
          </p>
        </header>

        <section
          v-for="(feature, index) in MARKETING_FEATURE_TOUR"
          :key="feature.id"
          class="marketing__feature"
          :aria-labelledby="`marketing-feature-${feature.id}`"
        >
          <div class="marketing__feature-copy">
            <p class="marketing__eyebrow" aria-hidden="true">
              {{ String(index + 1).padStart(2, "0") }}
            </p>
            <h3 :id="`marketing-feature-${feature.id}`">{{ feature.title }}</h3>
            <p>{{ feature.description }}</p>
          </div>

          <button
            type="button"
            class="marketing__shot"
            :aria-label="`View larger — ${feature.title}`"
            @click="openShot(feature, $event)"
          >
            <img
              class="marketing__screenshot"
              :src="feature.screenshot"
              :alt="feature.screenshotAlt"
              loading="lazy"
              width="1280"
              height="800"
            />
            <span class="marketing__shot-hint" aria-hidden="true">
              Click to enlarge
            </span>
          </button>
        </section>

        <p v-if="!submitted" class="marketing__tour-close">
          <a class="marketing__cta marketing__cta--primary" href="#marketing-interest"
            >Sound like your events? Tell us about your organization.</a
          >
        </p>
      </section>

      <section
        class="marketing__offerings-section"
        aria-labelledby="marketing-offerings"
      >
        <header class="marketing__section-head">
          <p class="marketing__eyebrow">Offerings</p>
          <h2 id="marketing-offerings">Three ways to run it</h2>
        </header>

        <div class="marketing__offerings">
          <section
            class="marketing__offering"
            aria-labelledby="marketing-offering-self-hosted"
          >
            <h3 id="marketing-offering-self-hosted">Self-hosted</h3>
            <p class="marketing__offering-for">
              For organizations with their own hardware and their own ops
              people.
            </p>
            <p>
              Meridian is free and open source. Run it on your own hardware, on
              your own terms, with every feature and no fee — the source is
              yours to read and the deployment is yours to keep.
            </p>
          </section>

          <section
            class="marketing__offering"
            aria-labelledby="marketing-offering-self-starter"
          >
            <h3 id="marketing-offering-self-starter">Hosted self-starter</h3>
            <p class="marketing__offering-for">
              For organizations that want the hosting handled and the running
              kept in-house.
            </p>
            <p>
              We host Meridian for your organization and you run it yourselves,
              without support, at a lower fee than the managed offering. The
              platform stays up; the operating stays yours.
            </p>
          </section>

          <section
            class="marketing__offering marketing__offering--managed"
            aria-labelledby="marketing-offering-managed"
          >
            <h3 id="marketing-offering-managed">Hosted and managed</h3>
            <p class="marketing__offering-for">
              For organizations that want the system to be somebody else's job.
            </p>
            <p>
              Fully hosted with full support — setup, operations, and an
              on-site technician at your event, so the system is somebody
              else's job while the event is yours.
            </p>
          </section>
        </div>

        <p class="marketing__quiet">
          There is nothing to buy on this page, and no signup to click through.
          Every offering starts the same way:
          <a v-if="!submitted" href="#marketing-interest"
            >tell us about your organization</a
          ><span v-else>tell us about your organization</span>
          and somebody will get back to you.
        </p>
      </section>

      <section
        v-if="submitted"
        class="marketing__card marketing__interest"
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
        class="marketing__card marketing__interest"
        aria-labelledby="marketing-interest"
      >
        <p class="marketing__eyebrow">Get in touch</p>
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

      <section class="marketing__signin" aria-labelledby="marketing-already">
        <h2 id="marketing-already">Already working an event?</h2>
        <p>
          <RouterLink :to="{ name: 'login' }">Sign in</RouterLink> if your
          organization already uses this deployment.
        </p>
      </section>

      <!--
        The screenshot viewer. Same committed asset, shown at the size it was
        captured at, so "see more of the interface" costs one click and zero
        extra requests to anything.
      -->
      <div
        v-if="enlarged !== null"
        class="marketing__viewer"
        role="dialog"
        aria-modal="true"
        :aria-label="`${enlarged.title} — screenshot`"
        @click.self="closeShot"
        @keydown.esc="closeShot"
      >
        <figure class="marketing__viewer-body">
          <button
            ref="viewerClose"
            type="button"
            class="marketing__viewer-close"
            aria-label="Close screenshot viewer"
            @click="closeShot"
          >
            ×
          </button>
          <img :src="enlarged.screenshot" :alt="enlarged.screenshotAlt" />
          <figcaption>
            <strong>{{ enlarged.title }}.</strong>
            {{ enlarged.screenshotAlt }}
          </figcaption>
        </figure>
      </div>
    </template>
  </main>
</template>

<style scoped>
.marketing {
  max-width: 64rem;
  margin: 0 auto;
  padding: var(--m-space-6, 1.5rem);
  display: flex;
  flex-direction: column;
  gap: var(--m-space-8, 2rem);
  font-family: var(--m-font-body, system-ui, sans-serif);
  color: var(--m-text-primary, #151a1f);
}

.marketing__masthead {
  display: flex;
  align-items: center;
  gap: var(--m-space-3, 0.75rem);
}

.marketing__mark {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: var(--m-radius-sm, 0.375rem);
  background: var(--m-platform-primary, #475157);
  color: var(--m-text-inverse, #fffcf6);
  display: grid;
  place-items: center;
  font-weight: 700;
}

.marketing__product {
  font-weight: 600;
  font-size: 1.15rem;
}

/* ---- Hero ------------------------------------------------------------- */

.marketing__hero {
  padding: var(--m-space-8, 2rem) var(--m-space-6, 1.5rem);
  border-radius: var(--m-radius-lg, 0.875rem);
  background:
    radial-gradient(
      110% 160% at 85% -20%,
      color-mix(in srgb, var(--m-platform-tertiary, #a58667) 18%, transparent),
      transparent 60%
    ),
    color-mix(
      in srgb,
      var(--m-platform-secondary, #6b7562) 10%,
      var(--m-surface-base, #fffcf6)
    );
  border: 1px solid var(--m-border-subtle, #d8d2c4);
  display: flex;
  flex-direction: column;
  gap: var(--m-space-4, 1rem);
}

.marketing__eyebrow {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--m-platform-secondary, #6b7562);
}

.marketing__heading {
  margin: 0;
  font-family: var(--m-font-heading, inherit);
  font-size: clamp(1.9rem, 4.5vw, 2.9rem);
  line-height: 1.12;
  letter-spacing: -0.02em;
  max-width: 20ch;
}

.marketing__lede {
  margin: 0;
  max-width: 60ch;
  font-size: 1.08rem;
  line-height: 1.6;
}

.marketing__hero-actions {
  margin: 0;
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3, 0.75rem);
}

.marketing__cta {
  display: inline-block;
  padding: var(--m-space-3, 0.75rem) var(--m-space-5, 1.25rem);
  border-radius: var(--m-radius-pill, 999px);
  font-weight: 600;
  text-decoration: none;
}

.marketing__cta--primary {
  background: var(--m-action-primary-bg, #475157);
  color: var(--m-action-primary-text, #fffcf6);
  box-shadow: var(--m-shadow-sm, 0 1px 2px rgba(21, 26, 31, 0.08));
}

.marketing__cta--primary:hover {
  box-shadow: var(--m-shadow-md, 0 4px 12px rgba(21, 26, 31, 0.12));
}

.marketing__cta--quiet {
  color: var(--m-text-secondary, #475157);
  border: 1px solid var(--m-border-default, #8d8371);
}

.marketing__badges {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2, 0.5rem);
}

.marketing__badges li {
  padding: var(--m-space-1, 0.25rem) var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-pill, 999px);
  border: 1px solid var(--m-border-subtle, #d8d2c4);
  background: var(--m-surface-base, #fffcf6);
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--m-text-secondary, #475157);
}

/* ---- Prose cards ------------------------------------------------------ */

.marketing__pair {
  display: grid;
  gap: var(--m-space-4, 1rem);
}

@media (min-width: 720px) {
  .marketing__pair {
    grid-template-columns: 1fr 1fr;
  }
}

.marketing__card {
  padding: var(--m-space-6, 1.5rem);
  border-radius: var(--m-radius-md, 0.625rem);
  border: 1px solid var(--m-border-subtle, #d8d2c4);
  background: var(--m-surface-raised, #fffdf9);
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3, 0.75rem);
}

.marketing__card h2 {
  margin: 0;
  font-size: 1.2rem;
}

.marketing__card p {
  margin: 0;
  line-height: 1.6;
}

/* ---- Feature tour ------------------------------------------------------ */

.marketing__tour,
.marketing__offerings-section {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-6, 1.5rem);
}

.marketing__section-head {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
  max-width: 60ch;
}

.marketing__section-head h2 {
  margin: 0;
  font-size: clamp(1.5rem, 3vw, 2rem);
  letter-spacing: -0.01em;
}

.marketing__feature {
  display: grid;
  gap: var(--m-space-4, 1rem);
  align-items: center;
  padding: var(--m-space-5, 1.25rem);
  border-radius: var(--m-radius-md, 0.625rem);
  border: 1px solid var(--m-border-subtle, #d8d2c4);
  background: var(--m-surface-raised, #fffdf9);
}

@media (min-width: 860px) {
  .marketing__feature {
    grid-template-columns: minmax(18rem, 2fr) 3fr;
    gap: var(--m-space-6, 1.5rem);
  }

  /* Alternate sides so a long scroll reads as a tour, not a table. */
  .marketing__feature:nth-of-type(even) .marketing__feature-copy {
    order: 2;
  }
}

.marketing__feature-copy {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.marketing__feature-copy h3 {
  margin: 0;
  font-size: 1.3rem;
  letter-spacing: -0.01em;
}

.marketing__feature-copy p:not(.marketing__eyebrow) {
  margin: 0;
  line-height: 1.6;
}

.marketing__shot {
  position: relative;
  display: block;
  padding: 0;
  border: 1px solid var(--m-border-subtle, #d8d2c4);
  border-radius: var(--m-radius-md, 0.625rem);
  background: none;
  cursor: zoom-in;
  overflow: hidden;
  box-shadow: var(--m-shadow-sm, 0 1px 2px rgba(21, 26, 31, 0.08));
  transition: box-shadow 120ms ease;
}

.marketing__shot:hover,
.marketing__shot:focus-visible {
  box-shadow: var(--m-shadow-md, 0 4px 12px rgba(21, 26, 31, 0.12));
}

.marketing__shot:focus-visible {
  outline: 2px solid var(--m-focus-ring, #b35f14);
  outline-offset: 2px;
}

.marketing__screenshot {
  display: block;
  width: 100%;
  height: auto;
}

.marketing__shot-hint {
  position: absolute;
  right: var(--m-space-2, 0.5rem);
  bottom: var(--m-space-2, 0.5rem);
  padding: var(--m-space-1, 0.25rem) var(--m-space-2, 0.5rem);
  border-radius: var(--m-radius-pill, 999px);
  background: color-mix(in srgb, var(--m-text-primary, #151a1f) 78%, transparent);
  color: var(--m-text-inverse, #fffcf6);
  font-size: 0.75rem;
  font-weight: 600;
  opacity: 0;
  transition: opacity 120ms ease;
  pointer-events: none;
}

.marketing__shot:hover .marketing__shot-hint,
.marketing__shot:focus-visible .marketing__shot-hint {
  opacity: 1;
}

.marketing__tour-close {
  margin: 0;
}

/* ---- Offerings --------------------------------------------------------- */

.marketing__offerings {
  display: grid;
  gap: var(--m-space-4, 1rem);
}

@media (min-width: 860px) {
  .marketing__offerings {
    grid-template-columns: repeat(3, 1fr);
  }
}

.marketing__offering {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
  padding: var(--m-space-5, 1.25rem);
  border: 1px solid var(--m-border-subtle, #d8d2c4);
  border-top: 3px solid var(--m-platform-secondary, #6b7562);
  border-radius: var(--m-radius-md, 0.625rem);
  background: var(--m-surface-raised, #fffdf9);
}

.marketing__offering--managed {
  border-top-color: var(--m-platform-accent, #cc792f);
}

.marketing__offering h3 {
  margin: 0;
  font-size: 1.1rem;
}

.marketing__offering p {
  margin: 0;
  line-height: 1.55;
}

.marketing__offering-for {
  font-size: 0.88rem;
  font-weight: 600;
  color: var(--m-text-muted, #5f665f);
}

/* ---- Interest form and footer ------------------------------------------ */

.marketing__interest {
  scroll-margin-top: var(--m-space-6, 1.5rem);
}

.marketing__signin {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.marketing__signin h2 {
  margin: 0;
  font-size: 1.05rem;
}

.marketing__signin p {
  margin: 0;
}

.marketing__quiet {
  margin: 0;
  color: var(--m-text-muted, #5f665f);
  font-size: 0.925rem;
  line-height: 1.55;
}

.marketing__notice,
.marketing__error {
  margin: 0;
  padding: var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-sm, 0.375rem);
  border: 1px solid var(--m-border-default, #8d8371);
}

.marketing__error {
  border-color: var(--m-status-danger, #cc792f);
}

.marketing__form {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3, 0.75rem);
}

.marketing__form button[type="submit"] {
  align-self: flex-start;
  padding: var(--m-space-3, 0.75rem) var(--m-space-6, 1.5rem);
  border: none;
  border-radius: var(--m-radius-pill, 999px);
  background: var(--m-action-primary-bg, #475157);
  color: var(--m-action-primary-text, #fffcf6);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.marketing__form button[type="submit"]:disabled {
  opacity: 0.55;
  cursor: default;
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
  border-radius: var(--m-radius-sm, 0.375rem);
  border: 1px solid var(--m-border-default, #8d8371);
  background: var(--m-surface-base, #fffcf6);
  color: inherit;
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

/* ---- Screenshot viewer -------------------------------------------------- */

.marketing__viewer {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: grid;
  place-items: center;
  padding: var(--m-space-5, 1.25rem);
  background: color-mix(in srgb, var(--m-text-primary, #151a1f) 72%, transparent);
}

.marketing__viewer-body {
  position: relative;
  margin: 0;
  max-width: min(90rem, 100%);
  max-height: 100%;
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
  padding: var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-lg, 0.875rem);
  background: var(--m-surface-base, #fffcf6);
  box-shadow: var(--m-shadow-overlay, 0 12px 32px rgba(21, 26, 31, 0.2));
}

.marketing__viewer-body img {
  display: block;
  max-width: 100%;
  max-height: calc(100vh - 9rem);
  width: auto;
  height: auto;
  margin: 0 auto;
  border-radius: var(--m-radius-sm, 0.375rem);
  border: 1px solid var(--m-border-subtle, #d8d2c4);
}

.marketing__viewer-body figcaption {
  font-size: 0.9rem;
  color: var(--m-text-muted, #5f665f);
  line-height: 1.5;
}

.marketing__viewer-close {
  position: absolute;
  top: var(--m-space-2, 0.5rem);
  right: var(--m-space-2, 0.5rem);
  width: 2.25rem;
  height: 2.25rem;
  border: 1px solid var(--m-border-default, #8d8371);
  border-radius: var(--m-radius-pill, 999px);
  background: var(--m-surface-base, #fffcf6);
  color: inherit;
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
}

.marketing__viewer-close:focus-visible {
  outline: 2px solid var(--m-focus-ring, #b35f14);
  outline-offset: 2px;
}
</style>
