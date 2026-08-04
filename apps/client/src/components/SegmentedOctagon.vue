<script setup lang="ts">
import { computed } from "vue";

/**
 * An inner octagon ringed by eight separated segments (M18.21B).
 *
 * The geometry is the product owner's baseline, unchanged: eight vertices at
 * 45° from a start angle of -112.5°, which is what gives the flat top, bottom,
 * left, and right sides. Three radii and one inset do all the spacing work —
 * `centerRadius` and `segmentInnerRadius` differ, and that difference *is* the
 * channel around the middle; `segmentEndInset` shortens every segment at both
 * ends, and that gap *is* the seam between neighbours. Changing the look means
 * changing those four numbers rather than nudging a polygon.
 *
 * Two things depart from the baseline deliberately, and both are about
 * Meridian rather than taste:
 *
 *  1. **Colour comes from the platform palette.** The baseline picks eight
 *     hues by hand. Meridian has four organization-settable colours, and
 *     BRAND-007 says accent, status, and series tokens resolve *through* that
 *     palette rather than being set independently — so eight fixed hues would
 *     be eight colours no organization can theme. The ring is instead four
 *     platform colours at two strengths each, ordered so no two neighbours
 *     share one.
 *  2. **Text is foreground on a tint, not white on a fill.** The baseline
 *     leans on `text-shadow` to hold white text over saturated colour, which
 *     is exactly the trade this page cannot make: it is read outdoors, at
 *     night, on a phone, by somebody about to drive to a gate. Each segment is
 *     a light wash of its colour with the normal text colour on top, and the
 *     colour returns at full strength on the rim, where it identifies the
 *     segment without standing between the reader and the words.
 *
 * The segments are controls, not decoration. A 164×126 box cannot hold a
 * policy document, so this is a navigator: it carries a heading and a short
 * summary, and selecting one hands the full content to whatever is rendering
 * the detail beside it.
 */
export interface OctagonSegment {
  /** Stable key, echoed back on select. */
  readonly key: string;
  readonly title: string;
  /** One or two lines. Longer text is the detail panel's job. */
  readonly summary: string;
  /** An action this segment offers, where it offers one. */
  readonly linkHref?: string | null;
  readonly linkLabel?: string;
}

const emit = defineEmits<{ (event: "select", key: string): void }>();

const props = withDefaults(
  defineProps<{
    segments: readonly OctagonSegment[];
    centerTitle: string;
    centerSubtitle?: string;
    /** The open segment's key, or null. */
    selected?: string | null;
    label?: string;
  }>(),
  {
    centerSubtitle: "",
    selected: null,
    label: "Event information",
  },
);

/* The baseline's constants, named the same so the two can be compared. */
const VIEWBOX = 1180;
const CENTER = { x: VIEWBOX / 2, y: VIEWBOX / 2 };
const CENTER_RADIUS = 200;
const SEGMENT_INNER_RADIUS = 218;
/*
 * The band is deliberately thick. The baseline's proportions put a 164x126
 * box in each segment, which holds a heading and a line — enough for a
 * diagram, not enough for the content this page carries. Pushing the outer
 * radius out while holding the centre still widens every segment without
 * touching the geometry that makes the ring an octagon, and it is what lets
 * the words live in the shape instead of in a dialog over it.
 */
const OUTER_RADIUS = 585;
const SEGMENT_END_INSET = 0.055;
const START_ANGLE = -112.5;
const TEXT_BOX = { width: 330, height: 236 };
const TEXT_PULL = 0.02;

/**
 * Which palette colour each segment carries, clockwise from the top.
 *
 * Adjacent duplicates are the point: pairs of neighbouring shapes share a
 * colour, and `accent` wraps from the last segment to the first, so every
 * colour occupies one contiguous arc exactly as the mark does.
 */
const RING_TONES = [
  "accent",
  "tertiary",
  "tertiary",
  "primary",
  "primary",
  "secondary",
  "secondary",
  "accent",
] as const;

interface Point {
  readonly x: number;
  readonly y: number;
}

function octagonPoints(radius: number): Point[] {
  return Array.from({ length: 8 }, (_unused, index) => {
    const angle = ((START_ANGLE + index * 45) * Math.PI) / 180;

    return {
      x: CENTER.x + radius * Math.cos(angle),
      y: CENTER.y + radius * Math.sin(angle),
    };
  });
}

function interpolate(from: Point, to: Point, amount: number): Point {
  return {
    x: from.x + (to.x - from.x) * amount,
    y: from.y + (to.y - from.y) * amount,
  };
}

function pointString(points: readonly Point[]): string {
  return points.map(({ x, y }) => `${x.toFixed(3)},${y.toFixed(3)}`).join(" ");
}

function averagePoint(points: readonly Point[]): Point {
  return points.reduce(
    (result, point) => ({
      x: result.x + point.x / points.length,
      y: result.y + point.y / points.length,
    }),
    { x: 0, y: 0 },
  );
}

function onKeydown(event: KeyboardEvent, key: string): void {
  if (event.key === "Enter" || event.key === " ") {
    event.preventDefault();
    emit("select", key);
  }
}

const centerPolygon = computed(() => pointString(octagonPoints(CENTER_RADIUS)));

/** One entry per rendered segment: its polygon, its text box, and its content. */
const rendered = computed(() => {
  const outer = octagonPoints(OUTER_RADIUS);
  const inner = octagonPoints(SEGMENT_INNER_RADIUS);

  return props.segments.slice(0, 8).map((segment, index) => {
    const next = (index + 1) % 8;

    const points = [
      interpolate(outer[index]!, outer[next]!, SEGMENT_END_INSET),
      interpolate(outer[index]!, outer[next]!, 1 - SEGMENT_END_INSET),
      interpolate(inner[index]!, inner[next]!, 1 - SEGMENT_END_INSET),
      interpolate(inner[index]!, inner[next]!, SEGMENT_END_INSET),
    ];

    // Pulled a few pixels inward so the words favour the middle while staying
    // centred within their own segment.
    const anchor = interpolate(averagePoint(points), CENTER, TEXT_PULL);

    /*
     * Colour follows the arrangement in the Meridian mark: four colours in
     * contiguous arcs, not one hue per segment cycling round the ring. The
     * mark puts orange at the top, tan down the right, slate across the
     * bottom, and sage up the left, and each colour holds two neighbouring
     * shapes so the ring reads as four quarters rather than eight unrelated
     * pieces.
     *
     * The four are the palette tokens themselves — `tokens.css` calls them
     * "logo colors in priority order" and they are exactly the mark's slate,
     * sage, tan, and orange — so this is the arrangement rather than a second
     * copy of the colours, and an organization that sets its own palette gets
     * its own four in the same arrangement (BRAND-007).
     */
    return {
      ...segment,
      points: pointString(points),
      tone: RING_TONES[index % RING_TONES.length],
      textX: anchor.x - TEXT_BOX.width / 2,
      textY: anchor.y - TEXT_BOX.height / 2,
    };
  });
});
</script>

<template>
  <svg
    class="octagon"
    :viewBox="`0 0 ${VIEWBOX} ${VIEWBOX}`"
    :aria-label="label"
    role="group"
  >
    <g
      v-for="segment in rendered"
      :key="segment.key"
      class="octagon__segment"
      :data-tone="segment.tone"
      :data-selected="segment.key === selected ? 'true' : 'false'"
      role="button"
      tabindex="0"
      :aria-label="`${segment.title}. ${segment.summary}`"
      @click="emit('select', segment.key)"
      @keydown="onKeydown($event, segment.key)"
    >
      <polygon class="octagon__shape" :points="segment.points" />

      <!--
        A foreignObject rather than <text>, because the copy has to wrap and
        balance like prose. It takes no pointer events, so the whole segment
        stays one target rather than the words being a hole in it.
      -->
      <foreignObject
        :x="segment.textX"
        :y="segment.textY"
        :width="TEXT_BOX.width"
        :height="TEXT_BOX.height"
        pointer-events="none"
      >
        <div class="octagon__copy">
          <p class="octagon__title">{{ segment.title }}</p>
          <p class="octagon__summary">{{ segment.summary }}</p>
          <!--
            The one interactive thing in the ring. The copy layer takes no
            pointer events so the shapes stay clean, and the link opts back in
            for itself rather than the whole box becoming a target.
          -->
          <a
            v-if="segment.linkHref"
            class="octagon__link"
            :href="segment.linkHref"
          >
            {{ segment.linkLabel ?? "Open" }}
          </a>
        </div>
      </foreignObject>
    </g>

    <!-- Drawn last so its outline stays crisp over the ring. -->
    <polygon class="octagon__center" :points="centerPolygon" />

    <foreignObject
      :x="CENTER.x - CENTER_RADIUS + 26"
      :y="CENTER.y - CENTER_RADIUS + 26"
      :width="(CENTER_RADIUS - 26) * 2"
      :height="(CENTER_RADIUS - 26) * 2"
      pointer-events="none"
    >
      <div class="octagon__center-copy">
        <p class="octagon__center-title">{{ centerTitle }}</p>
        <p v-if="centerSubtitle" class="octagon__center-subtitle">
          {{ centerSubtitle }}
        </p>
      </div>
    </foreignObject>
  </svg>
</template>

<style scoped>
.octagon {
  display: block;
  width: 100%;
  height: auto;
  overflow: visible;
}

/*
 * The fill washes the segment's colour into the page surface so the text on
 * top keeps the contrast it has everywhere else, and the rim carries that same
 * colour at full strength — which is where it identifies the segment without
 * standing between the reader and the words.
 */
.octagon__segment {
  --m-tone-wash: 18%;
}

.octagon__segment[data-tone="primary"] {
  --m-tone: var(--m-platform-primary);
}

.octagon__segment[data-tone="secondary"] {
  --m-tone: var(--m-platform-secondary);
}

.octagon__segment[data-tone="tertiary"] {
  --m-tone: var(--m-platform-tertiary);
}

.octagon__segment[data-tone="accent"] {
  --m-tone: var(--m-platform-accent);
}

.octagon__shape {
  fill: color-mix(
    in srgb,
    var(--m-tone) var(--m-tone-wash),
    var(--m-surface-raised)
  );
  stroke: color-mix(in srgb, var(--m-tone) 70%, var(--m-border-default));
  stroke-width: 2;
  stroke-linejoin: round;
  vector-effect: non-scaling-stroke;
  transition:
    fill 140ms ease,
    stroke-width 140ms ease;
}

.octagon__segment {
  cursor: pointer;
}

.octagon__segment:hover .octagon__shape {
  fill: color-mix(in srgb, var(--m-tone) 26%, var(--m-surface-raised));
}

.octagon__segment[data-selected="true"] .octagon__shape {
  fill: color-mix(in srgb, var(--m-tone) 32%, var(--m-surface-raised));
  stroke-width: 4;
}

/*
 * Keyboard focus is its own state and never borrowed from hover: a focus ring
 * that looked like a hover would leave a keyboard user unable to tell where
 * they are.
 */
.octagon__segment:focus-visible {
  outline: none;
}

.octagon__segment:focus-visible .octagon__shape {
  stroke: var(--m-focus-ring);
  stroke-width: 5;
}

/*
 * The link is a second control inside the first, so it carries its own focus.
 * Keyboard focus is drawn separately from hover: a focus ring that looked
 * like a hover would leave a keyboard user unable to tell where they are.
 */
.octagon__link {
  pointer-events: auto;
  margin-top: 2px;
  padding: 6px 14px;
  border-radius: 8px;
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-fg, var(--m-surface-base));
  font-size: 14px;
  font-weight: 800;
  text-decoration: none;
}

.octagon__link:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.octagon__copy {
  display: flex;
  height: 100%;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 6px;
  overflow: hidden;
  color: var(--m-text-primary);
  text-align: center;
  text-wrap: balance;
}

.octagon__title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: 19px;
  font-weight: 800;
  line-height: 1.2;
}

.octagon__summary {
  margin: 0;
  color: var(--m-text-secondary);
  font-size: 15px;
  font-weight: 500;
  line-height: 1.4;
}

.octagon__center {
  fill: var(--m-surface-base);
  stroke: var(--m-border-default);
  stroke-width: 2.5;
  stroke-linejoin: round;
  vector-effect: non-scaling-stroke;
}

.octagon__center-copy {
  display: flex;
  height: 100%;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  text-align: center;
  text-wrap: balance;
}

.octagon__center-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: 22px;
  font-weight: 800;
  line-height: 1.15;
  color: var(--m-text-primary);
}

.octagon__center-subtitle {
  margin: 0;
  font-size: 13px;
  font-weight: 500;
  line-height: 1.3;
  color: var(--m-text-secondary);
}
</style>
