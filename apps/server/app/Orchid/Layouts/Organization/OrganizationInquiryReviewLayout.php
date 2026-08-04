<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

/**
 * The one editable part of an inquiry: what the console has done about it
 * (PUBLIC-004).
 *
 * The note is where an operator writes what happened next — who replied, what
 * was agreed, why it was closed — so the next person to open the row is not
 * reading a status word and guessing.
 */
class OrganizationInquiryReviewLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            TextArea::make('review.review_notes')
                ->title(__('Review notes'))
                ->rows(5)
                ->maxlength(5000)
                ->help(__('Internal. The contact never sees this.')),
        ];
    }
}
