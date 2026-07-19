<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Node;

use App\Models\Node;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class NodeSettingsLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('node.node_name')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Node name'))
                ->placeholder(__('juplaya.2027.onsite'))
                ->help(__('This names the Meridian server/node, not a browser or mobile device.')),

            Select::make('node.node_role')
                ->options($this->roleOptions())
                ->required()
                ->title(__('Node role'))
                ->help(__('Use development for localhost. Event-mode roles require an HTTPS application URL.')),

            Input::make('node.central_node_url')
                ->type('url')
                ->max(2048)
                ->title(__('Central node URL'))
                ->placeholder(__('https://central.example.org')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function roleOptions(): array
    {
        $options = [];

        foreach (Node::ROLES as $role) {
            $options[$role] = ucfirst($role);
        }

        return $options;
    }
}
