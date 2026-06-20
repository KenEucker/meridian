<?php

namespace App\View\Components;

use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Services\Documents\DocumentRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

/**
 * Read-only policy/procedure reader with subtle type, scope, and version
 * metadata (UI implementation contract section 11.15).
 */
class DocumentViewer extends Component
{
    public readonly string $documentType;

    public readonly string $scope;

    public readonly string $state;

    public readonly string $version;

    public readonly HtmlString $renderedHtml;

    public function __construct(
        public readonly PolicyDocument|ProcedureDocument $document,
        DocumentRenderer $renderer,
    ) {
        $this->documentType = $document instanceof PolicyDocument ? 'Policy' : 'Procedure';
        $this->scope = $document::scopeTypeLabels()[$document->scope_type] ?? ucfirst($document->scope_type);
        $this->state = $document::stateLabels()[$document->state] ?? ucfirst($document->state);
        $this->version = $document->version();
        $this->renderedHtml = new HtmlString($renderer->render($document));
    }

    public function render(): View
    {
        return view('components.document-viewer');
    }
}
