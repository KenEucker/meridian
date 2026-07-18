<?php

namespace App\Models;

use Orchid\Attachment\Models\Attachment as OrchidPlatformAttachment;

/**
 * Orchid admin attachment model relocated off the domain `attachments` table.
 *
 * The physical Orchid table is `orchid_attachments` so data/API section 10.17
 * can use the documented `attachments` name for Meridian media metadata.
 */
class OrchidAttachment extends OrchidPlatformAttachment
{
    protected $table = 'orchid_attachments';
}
