<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Node;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class NodePairingLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('pairing.central_node_url')
                ->type('url')
                ->max(2048)
                ->title(__('Central node URL'))
                ->placeholder(__('https://central.example.org'))
                ->help(__('Leave blank to use the configured central node URL. Changing it requires pairing again.')),

            Input::make('pairing.token')
                ->type('text')
                ->max(255)
                ->title(__('One-time pairing token'))
                ->placeholder(__('mrdn-pair-...'))
                ->help(__('Create this token on the central node. It can pair one node once.')),
        ];
    }
}
