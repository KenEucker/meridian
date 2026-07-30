{{--
    Technician documentation, served from content packaged with this deployment
    (GOD-014). No request here reaches the network, and only `docs/technician/`
    is packaged, so specification, QA, planning, and issue documents are not
    reachable from this page (GOD-015).
--}}
<div class="bg-white rounded shadow-sm p-4">
    @if (! $packaged)
        <div class="alert alert-warning mb-0" role="status">
            <strong>No technician documentation is packaged with this build.</strong>
            Run <code>corepack pnpm run docs:package</code> before packaging the deployment.
        </div>
    @else
        {{-- Documentation version against build version (GOD-017). --}}
        <p class="text-muted">
            Documentation version <strong>{{ $documentation_version ?? 'unknown' }}</strong>,
            running build <strong>{{ $build_version }}</strong>.
            @unless ($versions_match)
                <span class="text-danger">
                    These do not match. A procedure here may describe a different build.
                </span>
            @endunless
        </p>

        <form method="get" action="{{ route('platform.documentation') }}" class="row g-2 align-items-end mb-4">
            <div class="col-sm-6">
                <label class="form-label" for="documentation-filter">Filter by title or heading</label>
                <input type="search"
                       class="form-control"
                       id="documentation-filter"
                       name="filter"
                       value="{{ $filter }}"
                       placeholder="pairing, conflict, secrets">
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
            @if ($filter !== '')
                <div class="col-sm-auto">
                    <a class="btn btn-link" href="{{ route('platform.documentation') }}">Clear</a>
                </div>
            @endif
        </form>

        <div class="row">
            <div class="col-md-4 mb-4">
                <h3 class="h6">Documents</h3>

                @if (count($documents) === 0)
                    <p class="text-muted mb-0">No document title or heading matches that filter.</p>
                @else
                    <ul class="list-group">
                        @foreach ($documents as $document)
                            <li class="list-group-item {{ $selected && $selected['slug'] === $document['slug'] ? 'active' : '' }}">
                                <a href="{{ route('platform.documentation', ['doc' => $document['slug'], 'filter' => $filter]) }}"
                                   class="{{ $selected && $selected['slug'] === $document['slug'] ? 'text-white' : '' }}">
                                    {{ $document['title'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="col-md-8">
                @if ($selected)
                    <article class="meridian-technician-doc">
                        {!! $selected['html'] !!}
                    </article>
                @else
                    <p class="text-muted mb-0">Select a document.</p>
                @endif
            </div>
        </div>
    @endif
</div>
