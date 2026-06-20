@if ($fragment->exists && $referencingDocuments !== [])
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th scope="col">Type</th>
                    <th scope="col">Title</th>
                    <th scope="col">Scope</th>
                    <th scope="col">State</th>
                    <th scope="col">Version</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($referencingDocuments as $document)
                    <tr>
                        <td>{{ $document['type'] }}</td>
                        <td><a href="{{ route($document['route'], $document['id']) }}">{{ $document['title'] }}</a></td>
                        <td>{{ $document['scope'] }}</td>
                        <td>{{ $document['state'] }}</td>
                        <td>{{ $document['version'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@elseif ($fragment->exists)
    <p class="mb-0 text-muted">No policy or procedure documents currently reference this fragment.</p>
@else
    <p class="mb-0 text-muted">Save the fragment before its referencing documents can be shown.</p>
@endif
