<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Department;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Orchid\Layouts\Department\DepartmentBrandingLayout;
use App\Orchid\Layouts\Department\DepartmentEditLayout;
use App\Orchid\Support\BrandingScreenSupport;
use App\Services\Branding\BrandingAdminService;
use App\Services\Branding\BrandingPalette;
use App\Services\Branding\Lettermark;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class DepartmentEditScreen extends Screen
{
    use BrandingScreenSupport;

    /**
     * @var Department
     */
    public $department;

    /**
     * @return array<string, mixed>
     */
    public function query(Department $department): iterable
    {
        $logoUrl = $this->brandingAssetUrl($department->branding_logo_attachment_id);

        return [
            'department' => $department,
            'branding' => [
                // The colour inputs are pre-filled from the organization
                // palette when the department has set nothing, so an operator
                // who ticks "this department has an accent" starts from a
                // colour that belongs to the organization rather than black.
                'accent' => $department->branding_accent_color ?? $this->paletteFallback($department),
                'surface' => $department->branding_surface_color ?? $this->paletteFallback($department),
                'set_accent' => $department->branding_accent_color !== null,
                'set_surface' => $department->branding_surface_color !== null,
                'logo_url' => $logoUrl,
            ],
            'branding_slots' => [
                [
                    'label' => __('Department logo'),
                    'url' => $logoUrl,
                    'lettermark' => Lettermark::forName((string) $department->name),
                ],
            ],
        ];
    }

    private function paletteFallback(Department $department): string
    {
        $organization = $department->organization;

        return BrandingPalette::fromStored(
            $organization instanceof Organization ? $organization->branding_palette_json : null,
        )->primary()->hex;
    }

    public function name(): ?string
    {
        return $this->department->exists ? 'Edit Department' : 'Create Department';
    }

    public function description(): ?string
    {
        return 'Department identity and organization ownership.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.departments',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Cancel'))
                ->icon('bs.x-circle')
                ->route('platform.departments'),

            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        $layouts = [
            Layout::block(DepartmentEditLayout::class)
                ->title(__('Department'))
                ->description(__('Departments manage persistent operational units within an organization.')),
        ];

        // Branding needs a department to attach to, which arrives with the
        // first save.
        if ($this->department->exists) {
            $layouts[] = Layout::view('orchid.branding.assets');
            $layouts[] = Layout::block(DepartmentBrandingLayout::class)
                ->title(__('Branding'))
                ->description($this->brandingDescription());
        }

        return $layouts;
    }

    private function brandingDescription(): string
    {
        $base = __(
            'Logo, accent color, and surface background only. Everything else resolves from the organization palette. '
            .'Department leads normally edit this on the product branding surface; this console shows and repairs the '
            .'stored values.'
        );

        $organizationId = $this->department->organization_id !== null
            ? (string) $this->department->organization_id
            : null;

        $lock = $this->brandingLockReason($organizationId);

        if ($lock !== null) {
            return $base.' '.$lock;
        }

        $organization = $this->department->organization;

        // BRAND-013 is worth saying here even though the save still succeeds:
        // an operator setting an accent that will not render should know why
        // before they wonder whether it saved.
        return $organization instanceof Organization && ! $organization->department_branding_enabled
            ? $base.' '.__('This organization has department branding overrides switched off, so accent and background changes are refused until an organizer re-enables them.')
            : $base;
    }

    public function save(Request $request, Department $department): RedirectResponse
    {
        $organizationId = $request->input('department.organization_id');

        $validated = $request->validate([
            'department.organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'department.name' => ['required', 'string', 'max:255'],
            'department.code' => [
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique(Department::class, 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($department),
            ],
            'department.description' => ['nullable', 'string'],
            'branding.accent' => ['nullable', 'string', 'max:32'],
            'branding.surface' => ['nullable', 'string', 'max:32'],
            'branding.set_accent' => ['nullable', 'boolean'],
            'branding.set_surface' => ['nullable', 'boolean'],
        ]);

        $department->fill($validated['department'])->save();

        $this->saveBranding($request, $department);

        Toast::info(__('Department was saved.'));

        return redirect()->route('platform.departments');
    }

    /**
     * Apply the branding block through the same services the product path
     * uses, so Orchid inherits the contrast validation, the central-node
     * authority rule, the active-event freeze, and the audit record.
     */
    private function saveBranding(Request $request, Department $department): void
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $department->exists) {
            return;
        }

        $department->loadMissing('organization');

        // A colour input has no empty state — with nothing stored the browser
        // submits #000000 — so the checkbox beside it is what says whether the
        // department has that colour at all. Without this, saving the screen
        // just to attach a logo would post black and be refused for contrast.
        $accent = $request->boolean('branding.set_accent')
            ? $request->input('branding.accent')
            : null;

        $surface = $request->boolean('branding.set_surface')
            ? $request->input('branding.surface')
            : null;

        $this->guardBranding(
            fn () => app(BrandingAdminService::class)->updateDepartmentBranding(
                $department,
                ['accent' => $accent, 'surface' => $surface],
                $actor,
                AuditEvent::SOURCE_ORCHID,
            ),
            'branding.accent',
        );

        $this->applyBrandingAsset(
            $request,
            $department,
            Attachment::BRANDING_SLOT_DEPARTMENT_LOGO,
            'branding.logo',
            'branding.remove_logo',
        );
    }
}
