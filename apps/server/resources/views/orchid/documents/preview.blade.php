@if ($document->exists)
    <x-document-viewer :document="$document" />
@else
    <p class="mb-0 text-muted">Save the document to review its rendered Markdown preview.</p>
@endif
