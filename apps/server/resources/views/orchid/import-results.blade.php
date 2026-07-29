@if (! empty($import_result))
    <div class="bg-white rounded shadow-sm p-4 mb-3">
        <h4 class="h5">
            {{ $import_result['preview'] ? __('Preview result') : __('Import result') }}
        </h4>

        @if ($import_result['preview'])
            <p class="text-muted">
                {{ __('Nothing was saved. This is what importing this file would do, row by row.') }}
            </p>
        @else
            <p class="text-muted">
                {{ __('Every created and updated record is recorded in the audit log.') }}
            </p>
        @endif

        <p class="mb-3">
            <span class="badge bg-success">{{ __('Created') }}: {{ $import_result['imported'] }}</span>
            <span class="badge bg-primary">{{ __('Updated') }}: {{ $import_result['updated'] }}</span>
            <span class="badge bg-secondary">{{ __('Skipped') }}: {{ $import_result['skipped'] }}</span>
        </p>

        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Row') }}</th>
                        <th scope="col">{{ __('Record') }}</th>
                        <th scope="col">{{ __('Outcome') }}</th>
                        <th scope="col">{{ __('Reason') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($import_result['rows'] as $row)
                        <tr>
                            <td>{{ $row['row'] }}</td>
                            <td>{{ $row['identifier'] !== '' ? $row['identifier'] : __('Unnamed') }}</td>
                            <td>
                                @if ($row['status'] === 'imported')
                                    {{ __('Created') }}
                                @elseif ($row['status'] === 'updated')
                                    {{ __('Updated') }}
                                @else
                                    {{ __('Skipped') }}
                                @endif
                            </td>
                            <td>{{ $row['reason'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
