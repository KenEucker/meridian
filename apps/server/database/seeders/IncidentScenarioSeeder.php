<?php

namespace Database\Seeders;

use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\Auth\DeviceTrustService;
use App\Services\FieldReports\FieldReportAcceptanceService;
use App\Services\Incidents\IncidentCreationService;
use App\Services\Incidents\IncidentFieldReportLinkService;
use App\Services\Incidents\IncidentLinkService;
use App\Services\Incidents\IncidentTimelineService;
use Database\Seeders\Support\ScenarioClock;
use Database\Seeders\Support\ScenarioContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Field Reports and the incidents some of them became.
 *
 * The IMS list is the one surface in the product whose controls are useless
 * against a short list: search, the status and priority filters, sorting, paging,
 * and saved presets all need enough rows that narrowing them changes what you
 * see. So this seeds a spread rather than a sample — every status, every
 * priority, times running back across the event, and titles with distinct words
 * in them so a free-text search has something to actually match on.
 *
 * The relationships matter as much as the rows. One incident carries a struck
 * note, because striking is append-only and the struck entry has to stay
 * visible. Two are linked to each other, because a link is symmetric and a
 * scenario with one incident cannot show it. And two Field Reports are attached
 * to incidents while a third is left loose, so the IMS review list has something
 * outstanding and the link picker has something to pick.
 */
class IncidentScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $context = new ScenarioContext;
        $event = $context->event();
        $ingrid = $context->user('ingrid');
        $omar = $context->user('omar');

        $reports = $this->seedFieldReports($context);
        $incidents = $this->seedIncidents($context);

        $timeline = app(IncidentTimelineService::class);
        $links = app(IncidentLinkService::class);
        $fieldReportLinks = app(IncidentFieldReportLinkService::class);

        /*
         * A note, and a note struck after the fact. Striking preserves the
         * entry rather than deleting it (INC-011), so the timeline has to
         * contain a stricken entry for anybody to see that it does.
         */
        $lostChild = $incidents['lost-child'];

        if ($lostChild->timelineEntries()->where('entry_type', 'note')->count() === 0) {
            $timeline->appendNote(
                $lostChild,
                $omar,
                'Parent reports last seen near the ice tent about twenty minutes ago.',
                ScenarioClock::hoursAgo(3.5),
            );

            $mistake = $timeline->appendNote(
                $lostChild,
                $omar,
                'Child described as wearing a red jacket — correction below, this was the parent.',
                ScenarioClock::hoursAgo(3.4),
            );

            $timeline->appendNote(
                $lostChild,
                $ingrid,
                'Child located at Ranger HQ, reunited with parent. Standing down.',
                ScenarioClock::hoursAgo(3),
            );

            $timeline->strikeNote(
                $lostChild,
                $mistake,
                $ingrid,
                'Described the wrong person.',
                ScenarioClock::hoursAgo(3.2),
            );
        }

        // Two incidents on the same stretch of fence, linked to each other.
        $alreadyLinked = $incidents['fence-breach']
            ->sourceIncidentLinks()
            ->where('target_incident_id', $incidents['fence-follow-up']->id)
            ->whereNull('unlinked_at')
            ->exists();

        if (! $alreadyLinked) {
            $links->link(
                $incidents['fence-breach'],
                $incidents['fence-follow-up'],
                $ingrid,
                ScenarioClock::hoursAgo(5),
            );
        }

        // Two reports became incidents; the third is still on the review list.
        foreach (
            [
                ['dust-storm', 'medical-standby'],
                ['fence', 'fence-breach'],
            ] as [$reportKey, $incidentKey]
        ) {
            $report = $reports[$reportKey];
            $incident = $incidents[$incidentKey];

            $alreadyAttached = $incident
                ->fieldReportLinks()
                ->where('field_report_id', $report->id)
                ->whereNull('unlinked_at')
                ->exists();

            if ($alreadyAttached) {
                continue;
            }

            $fieldReportLinks->link($incident, $report, $ingrid, ScenarioClock::hoursAgo(4));
        }
    }

    /**
     * @return array<string, FieldReport>
     */
    private function seedFieldReports(ScenarioContext $context): array
    {
        $acceptance = app(FieldReportAcceptanceService::class);
        $event = $context->event();
        $rangers = $context->department('RANGERS');
        $dirt = $context->team('RANGERS', 'DIRT');

        $definitions = [
            'dust-storm' => [
                'author' => 'vera',
                'title' => 'Dust storm rolling in from the north',
                'body' => 'Visibility dropping fast past the north berm. Recommend medical standby and a shelter call.',
                'submitted' => ScenarioClock::hoursAgo(6),
            ],
            'fence' => [
                'author' => 'nora',
                'title' => 'Perimeter fence down near marker 14',
                'body' => 'About four metres of fence flat on the ground. No one around it. Flagged it and moved on.',
                'submitted' => ScenarioClock::hoursAgo(5.5),
            ],
            'generator' => [
                'author' => 'sam',
                'title' => 'Generator noise complaint at centre camp',
                'body' => 'Two separate people asked about the generator behind the kitchen. Passing it along.',
                'submitted' => ScenarioClock::hoursAgo(2),
            ],
        ];

        $reports = [];

        /*
         * A Field Report is refused unless the device it came from is actively
         * trusted for the person submitting it (technical spec 12.4), so the
         * authors' trust records come first. This is not scaffolding around the
         * rule — it is the rule being satisfied the way a real device satisfies
         * it, which is also what puts rows on the device trust surfaces.
         */
        $trust = app(DeviceTrustService::class);

        foreach ($definitions as $definition) {
            $trust->trust($context->user($definition['author']), $context->device(), ScenarioClock::daysAgo(20));
        }

        foreach ($definitions as $key => $definition) {
            $existing = FieldReport::query()
                ->where('event_id', $event->id)
                ->where('title', $definition['title'])
                ->first();

            if ($existing !== null) {
                $reports[$key] = $existing;

                continue;
            }

            $reports[$key] = $acceptance->accept([
                'id' => (string) Str::uuid(),
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
                'team_id' => (string) $dirt->id,
                'submitted_by_user_id' => (string) $context->user($definition['author'])->id,
                'staff_id' => (string) $context->staff($definition['author'])->id,
                'title' => $definition['title'],
                'body' => $definition['body'],
                'device_submitted_at' => $definition['submitted'],
                'origin_device_id' => (string) $context->device()->id,
                'origin_node_id' => (string) $context->node()->id,
            ], $definition['submitted']);
        }

        return $reports;
    }

    /**
     * @return array<string, Incident>
     */
    private function seedIncidents(ScenarioContext $context): array
    {
        $creation = app(IncidentCreationService::class);
        $event = $context->event();
        $ingrid = $context->user('ingrid');
        $types = $this->typeNames($context);

        /*
         * Spread across status and priority on purpose. The list's filters are
         * the feature; a list where every row is an open Routine incident cannot
         * demonstrate that any of them narrow anything.
         */
        $definitions = [
            'lost-child' => [
                'title' => 'Child separated from parent near the ice tent',
                'status' => Incident::STATUS_CLOSED,
                'priority_label' => Incident::PRIORITY_SERIOUS,
                'location_name' => 'Ice tent',
                'started_at' => ScenarioClock::hoursAgo(4),
            ],
            'fence-breach' => [
                'title' => 'Perimeter fence down at marker 14',
                'status' => Incident::STATUS_MONITORING,
                'priority_label' => Incident::PRIORITY_IMPORTANT,
                'location_name' => 'Perimeter marker 14',
                'started_at' => ScenarioClock::hoursAgo(5),
            ],
            'fence-follow-up' => [
                'title' => 'Vehicle tracks beyond marker 14',
                'status' => Incident::STATUS_OPEN,
                'priority_label' => Incident::PRIORITY_IMPORTANT,
                'location_name' => 'Perimeter marker 14',
                'started_at' => ScenarioClock::hoursAgo(4.5),
            ],
            'medical-standby' => [
                'title' => 'Medical standby for incoming dust storm',
                'status' => Incident::STATUS_ON_SCENE,
                'priority_label' => Incident::PRIORITY_CRITICAL,
                'location_name' => 'Medical tent',
                'started_at' => ScenarioClock::hoursAgo(6),
            ],
            'noise' => [
                'title' => 'Generator noise behind the kitchen',
                'status' => Incident::STATUS_ON_HOLD,
                'priority_label' => Incident::PRIORITY_ROUTINE,
                'location_name' => 'Centre camp kitchen',
                'started_at' => ScenarioClock::hoursAgo(2),
            ],
            'lighting' => [
                'title' => 'Light tower out on the east road',
                'status' => Incident::STATUS_OPEN,
                'priority_label' => Incident::PRIORITY_ROUTINE,
                'location_name' => 'East road',
                'started_at' => ScenarioClock::hoursAgo(1),
            ],
        ];

        $incidents = [];
        $typeIndex = 0;

        foreach ($definitions as $key => $definition) {
            $existing = Incident::query()
                ->where('event_id', $event->id)
                ->where('title', $definition['title'])
                ->first();

            if ($existing !== null) {
                $incidents[$key] = $existing;

                continue;
            }

            $incidents[$key] = $creation->create(
                [
                    'event_id' => (string) $event->id,
                    ...$definition,
                    // Spread across the organization's configured types rather
                    // than filing everything under one, so the type filter has
                    // more than a single answer to give.
                    'incident_type_names' => $types === [] ? [] : [$types[$typeIndex % count($types)]],
                ],
                $ingrid,
                $definition['started_at'],
            );

            $typeIndex++;
        }

        return $incidents;
    }

    /**
     * @return list<string>
     */
    private function typeNames(ScenarioContext $context): array
    {
        return IncidentType::query()
            ->where('organization_id', $context->organization()->id)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }
}
