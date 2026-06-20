<article class="document-viewer" aria-labelledby="document-viewer-title-{{ $document->id }}">
    <header>
        <p>{{ $documentType }}</p>
        <h1 id="document-viewer-title-{{ $document->id }}">{{ $document->title }}</h1>
    </header>

    <div class="document-viewer__content">
        {{ $renderedHtml }}
    </div>

    <footer>
        <dl>
            <div>
                <dt>Scope</dt>
                <dd>{{ $scope }}</dd>
            </div>
            <div>
                <dt>Version</dt>
                <dd>{{ $version }}</dd>
            </div>
            <div>
                <dt>State</dt>
                <dd>{{ $state }}</dd>
            </div>
        </dl>
    </footer>
</article>
