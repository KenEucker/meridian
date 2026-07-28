{{--
    God Mode attention list (GOD-005 through GOD-011; technical spec 22.5.2).

    Three fixed groups, each item linking to the screen that resolves it. Groups
    with nothing in them are not rendered, and when nothing at all needs
    attention the screen says so rather than showing an empty region (GOD-011).

    Everything here is read at view time and nothing is written (GOD-010).
--}}
<div class="bg-white rounded shadow-sm p-4">
    <h3 class="h5 mb-3">Needs attention</h3>

    @if ($attention['all_clear'])
        <div class="alert alert-success mb-0" role="status">
            <strong>Nothing needs attention.</strong>
            Node configuration, organizational data, and the sync conflict queue are all clear.
        </div>
    @else
        <p class="text-muted">
            {{ trans_choice('{1}One item needs attention.|[2,*]:count items need attention.', $attention['count'], ['count' => $attention['count']]) }}
            Meridian reports these; it does not repair them for you.
        </p>

        @foreach ($attention['groups'] as $group)
            <h4 class="h6 mt-4">{{ $group['label'] }}</h4>
            <p class="text-muted small mb-2">{{ $group['description'] }}</p>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">Detail</th>
                            <th scope="col">Resolve</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['items'] as $item)
                            <tr>
                                <th scope="row">{{ $item['label'] }}</th>
                                <td>{{ $item['detail'] }}</td>
                                <td>
                                    <a href="{{ $item['resolve_url'] }}">{{ $item['resolve_label'] }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</div>
