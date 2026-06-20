@if ($document->exists && $document->fragmentReferences->isNotEmpty())
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Fragment</th>
                    <th scope="col">Scope</th>
                    <th scope="col">Current version</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($document->fragmentReferences as $reference)
                    <tr>
                        <td><code>{{ $reference->token }}</code></td>
                        <td>{{ $reference->fragment?->name ?? 'Missing fragment' }}</td>
                        <td>{{ $reference->fragment ? ($reference->fragment::scopeTypeLabels()[$reference->fragment->scope_type] ?? ucfirst($reference->fragment->scope_type)) : 'Unknown' }}</td>
                        <td>{{ $reference->fragment?->version ?? 'Unavailable' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@elseif ($document->exists)
    <p class="mb-0 text-muted">This document does not currently reference any fragments.</p>
@else
    <p class="mb-0 text-muted">Save the document before its resolved fragment references can be shown.</p>
@endif
