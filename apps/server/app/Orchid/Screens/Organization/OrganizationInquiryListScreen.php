<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Organization;

use App\Models\OrganizationInquiry;
use App\Orchid\Layouts\Organization\OrganizationInquiryListLayout;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * Organization interest submissions waiting to be read (M18.23; PUBLIC-004).
 *
 * God Mode rather than a product surface, because an inquiry belongs to no
 * organization: there is nobody inside Meridian whose organizer authority would
 * cover it. The people who read these are the people who run the deployment.
 */
class OrganizationInquiryListScreen extends Screen
{
    public const PERMISSION = 'platform.organization-inquiries';

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'inquiries' => OrganizationInquiry::query()
                ->with('reviewedBy')
                ->defaultSort('submitted_at', 'desc')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Organization Inquiries';
    }

    public function description(): ?string
    {
        return 'Organizations that wrote in from the public marketing surface. An inquiry is a message and nothing more: it creates no organization, no user, and no staff record (PUBLIC-003), and creating an organization stays a deliberate God Mode action taken from the Organizations screen (PUBLIC-004).';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            self::PERMISSION,
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            OrganizationInquiryListLayout::class,
        ];
    }
}
