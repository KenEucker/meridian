<?php

namespace Database\Seeders;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\Branding\BrandingAssetService;
use App\Services\FieldReports\FieldReportPhotoUploadService;
use Database\Seeders\Support\ScenarioClock;
use Database\Seeders\Support\ScenarioContext;
use Database\Seeders\Support\ScenarioImages;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pictures, so the surfaces that are built around them are not all placeholders.
 *
 * Three separate paths, and they are separate in the product too rather than
 * being one upload feature wearing three hats:
 *
 *  - **Staff profile pictures** are a path on the public disk recorded on the
 *    staff row, because a roster thumbnail is served to anybody who may see the
 *    roster and gains nothing from a signed URL.
 *  - **Branding logos** go through {@see BrandingAssetService} onto the private
 *    attachments disk, sniffed and re-decoded rather than trusted, because a
 *    logo is served inline into every signed-in surface.
 *  - **Field Report photos** go through {@see FieldReportPhotoUploadService},
 *    which verifies the client checksum, re-processes the bytes, and records
 *    device and node provenance — a photo from the field is evidence, and it is
 *    treated like it.
 *
 * Seeding all three matters because an empty image slot and a working one look
 * identical in a database and completely different on screen. A department
 * header with no logo silently falls back to a lettermark, a roster with no
 * avatars looks like a bug in the avatar component, and a Field Report with no
 * attachment never exercises the thumbnail, the lightbox, or the signed URL
 * that serves it.
 */
class ImageScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $context = new ScenarioContext;

        $this->seedStaffPictures();

        // Branding is frozen mid-event for the same reason policy is, so like
        // the document library this runs before the event's active window is
        // opened. An organization has its logo before it opens the gates.
        $this->seedBrandingLogos($context);

        $this->seedFieldReportPhotos($context);
    }

    /**
     * An avatar for everybody, and deliberately not for everybody.
     *
     * Two staff records are left without one, because the fallback is a real
     * rendering path — the product draws a lettermark when there is no picture —
     * and a roster where every row has an image never shows it.
     */
    private function seedStaffPictures(): void
    {
        $this->ensurePublicStorageIsReachable();

        $disk = Storage::disk('public');
        $staff = Staff::query()->orderBy('legal_name')->get();
        $withoutPictures = ['Ira Ineligible', 'Pat Prospective'];

        foreach ($staff as $member) {
            if (in_array($member->legal_name, $withoutPictures, true)) {
                continue;
            }

            $path = 'staff-pictures/'.Str::slug($member->legal_name).'.png';

            if ($member->profile_picture_path === $path && $disk->exists($path)) {
                continue;
            }

            $bytes = ScenarioImages::avatar($member->legal_name);
            $disk->put($path, $bytes);

            $member->forceFill([
                'profile_picture_path' => $path,
                'profile_picture_mime_type' => 'image/png',
                'profile_picture_size_bytes' => strlen($bytes),
                'profile_picture_width' => 320,
                'profile_picture_height' => 320,
                'profile_picture_uploaded_at' => ScenarioClock::daysAgo(25),
            ])->save();
        }
    }

    /**
     * Make sure `public/storage` exists, or say why the avatars will not load.
     *
     * The public disk serves at `{APP_URL}/storage`, which is a symlink nothing
     * in this repository ever created — so before this seeder existed, a staff
     * picture would have been written to disk and then 404'd, which looks
     * exactly like a broken avatar component. Creating it here keeps the seed
     * self-sufficient: `migrate:fresh --seed` is the one command a developer
     * runs, and it should leave a working database rather than a working
     * database and one manual step nobody wrote down.
     *
     * A failure is reported rather than thrown. Symlinks need Developer Mode or
     * an elevated shell on Windows, and a whole scenario is not worth losing
     * over thumbnails — the rest of the seed is still useful without them.
     */
    private function ensurePublicStorageIsReachable(): void
    {
        if (file_exists(public_path('storage'))) {
            return;
        }

        try {
            Artisan::call('storage:link');
        } catch (Throwable $exception) {
            $this->command?->warn(
                'Could not link public/storage, so seeded staff pictures will not load: '
                .$exception->getMessage().' Run `php artisan storage:link` from an elevated shell.',
            );
        }
    }

    /**
     * A lockup for the organization, a mark for each department, one team, and
     * both events.
     *
     * Every owner type the branding service accepts is represented, because the
     * slot rules differ per type — an organization has two slots and everybody
     * else has one — and a scenario that only brands the organization never
     * proves a department header resolves its own logo rather than inheriting.
     */
    private function seedBrandingLogos(ScenarioContext $context): void
    {
        $branding = app(BrandingAssetService::class);
        $olive = $context->user('olive');
        $organization = $context->organization();

        $this->putLogo($branding, $organization, Attachment::BRANDING_SLOT_FULL_LOCKUP, $organization->name, $olive);
        $this->putLogo($branding, $organization, Attachment::BRANDING_SLOT_COMPACT_MARK, ScenarioImages::initials($organization->name), $olive);

        foreach (Department::query()->where('organization_id', $organization->id)->get() as $department) {
            $this->putLogo($branding, $department, Attachment::BRANDING_SLOT_DEPARTMENT_LOGO, $department->name, $olive);
        }

        $dirt = $context->team('RANGERS', 'DIRT');
        $this->putLogo($branding, $dirt, Attachment::BRANDING_SLOT_TEAM_LOGO, $dirt->name, $olive);

        foreach ([$context->event(), $context->upcomingEvent()] as $event) {
            $this->putLogo($branding, $event, Attachment::BRANDING_SLOT_EVENT_LOGO, $event->name, $olive);
        }
    }

    private function putLogo(
        BrandingAssetService $branding,
        Organization|Department|Team|Event $owner,
        string $slot,
        string $label,
        User $actor,
    ): void {
        $column = match ($slot) {
            Attachment::BRANDING_SLOT_FULL_LOCKUP => 'branding_full_lockup_attachment_id',
            Attachment::BRANDING_SLOT_COMPACT_MARK => 'branding_compact_mark_attachment_id',
            default => 'branding_logo_attachment_id',
        };

        if ($owner->getAttribute($column) !== null) {
            return;
        }

        $bytes = $slot === Attachment::BRANDING_SLOT_COMPACT_MARK
            ? ScenarioImages::avatar($label, 256)
            : ScenarioImages::logo($label);

        $branding->put($owner, $slot, $bytes, $actor);
    }

    /**
     * Photos on two of the three Field Reports, and two on one of them.
     *
     * The cap is two per report, so one report carries the maximum and one
     * carries none — which is what makes the "add photo" affordance, the
     * remaining-slots count, and the no-attachment layout all reachable from the
     * same seeded event.
     */
    private function seedFieldReportPhotos(ScenarioContext $context): void
    {
        $uploads = app(FieldReportPhotoUploadService::class);

        $plan = [
            'Dust storm rolling in from the north' => [
                'Wall of dust past the north berm',
                'Same ridge, four minutes later',
            ],
            'Perimeter fence down near marker 14' => [
                'Fence flat on the ground at marker 14',
            ],
        ];

        foreach ($plan as $reportTitle => $captions) {
            $report = FieldReport::query()
                ->where('event_id', $context->event()->id)
                ->where('title', $reportTitle)
                ->first();

            if ($report === null) {
                continue;
            }

            $existing = Attachment::query()
                ->where('attachable_type', $report->getMorphClass())
                ->where('attachable_id', $report->id)
                ->count();

            if ($existing >= count($captions)) {
                continue;
            }

            foreach ($captions as $index => $caption) {
                $bytes = ScenarioImages::fieldPhoto($caption);

                $uploads->upload([
                    'id' => (string) Str::uuid(),
                    'field_report_id' => (string) $report->id,
                    'uploaded_by_user_id' => (string) $report->submitted_by_user_id,
                    'origin_device_id' => (string) $context->device()->id,
                    'origin_node_id' => (string) $context->node()->id,
                    'bytes' => $bytes,
                    'checksum_sha256' => hash('sha256', $bytes),
                    'declared_mime_type' => 'image/png',
                    'device_uploaded_at' => $report->device_submitted_at?->copy()->addMinutes($index + 1),
                ], ScenarioClock::hoursAgo(5));
            }
        }
    }
}
