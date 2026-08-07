<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Document;

use App\Models\DocumentAcknowledgment;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * Acknowledgment review (M18.34; POL-043 through POL-045).
 *
 * Newest first, because the question this screen is opened with is usually
 * whether somebody has accepted something yet.
 *
 * The document revision has a column of its own rather than being folded into
 * the title. POL-045 makes an acceptance an acceptance of a *version*, and the
 * whole reason somebody reads this list is to find out which one. The fragment
 * revision sits beside it for the same reason: a document whose words changed
 * because a fragment it embeds changed is a different document to have read
 * (POL-047), and the two revisions move independently.
 */
class DocumentAcknowledgmentListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'acknowledgments';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('acknowledged_at', __('Accepted'))
                ->usingComponent(DateTimeSplit::class)
                ->sort()
                ->defaultHidden(false)
                ->cantHide(),

            TD::make('user', __('Who'))
                ->cantHide()
                ->render(fn (DocumentAcknowledgment $acknowledgment) => e(
                    $acknowledgment->staff?->displayName()
                        ?? $acknowledgment->user?->name
                        ?? '—',
                )),

            TD::make('document_type', __('Kind'))
                ->sort()
                ->filter(Input::make()),

            TD::make('document', __('Document'))
                ->render(fn (DocumentAcknowledgment $acknowledgment) => e(
                    $acknowledgment->describeDocument(),
                )),

            TD::make('document_revision', __('Revision'))
                ->sort()
                ->align(TD::ALIGN_RIGHT),

            TD::make('fragment_revision', __('Fragment revision'))
                ->sort()
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (DocumentAcknowledgment $acknowledgment) => e(
                    $acknowledgment->fragment_revision === null
                        ? '—'
                        : (string) $acknowledgment->fragment_revision,
                )),

            TD::make('scope_type', __('Asked in'))
                ->sort()
                ->render(fn (DocumentAcknowledgment $acknowledgment) => e(
                    $acknowledgment->describeScope(),
                )),

            TD::make('accepted_by_node', __('Accepted on'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (DocumentAcknowledgment $acknowledgment) => e(
                    $acknowledgment->acceptedByNode?->node_name ?? '—',
                )),
        ];
    }
}
