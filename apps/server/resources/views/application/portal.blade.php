<!DOCTYPE html>
<html lang="en">
<head>
    @include('meridian.surface-head')
    <title>Your applications</title>
</head>
<body>
    <main>
        @include('meridian.brand', ['context' => __('Applications')])

        <h1>Your applications</h1>
        <p class="lede">Everything submitted with {{ $email }}.</p>

        @if ($withdrawnApplicationId)
            <p class="status">That application has been withdrawn. Nobody will review it.</p>
        @endif

        @error('withdraw')
            <p class="errors">{{ $message }}</p>
        @enderror

        @if ($applications->isEmpty())
            <div class="panel">
                <p>There are no applications under this address.</p>
            </div>
        @else
            <div class="applications">
                @foreach ($applications as $application)
                    <section class="panel application" aria-labelledby="application-{{ $application->id }}">
                        <h2 id="application-{{ $application->id }}">
                            {{ $application->isOrganizationScoped()
                                ? $application->organization?->name ?? __('An organization')
                                : $application->event?->name ?? __('An event') }}
                        </h2>

                        <dl>
                            <dt>What you applied to</dt>
                            <dd>
                                {{ $application->isOrganizationScoped()
                                    ? __('Joining :organization', ['organization' => $application->organization?->name ?? __('the organization')])
                                    : __('Staffing :event', ['event' => $application->event?->name ?? __('the event')]) }}
                            </dd>

                            <dt>Submitted</dt>
                            <dd>
                                <time datetime="{{ optional($application->submitted_at)->toIso8601String() }}">
                                    {{ optional($application->submitted_at)->toDayDateTimeString() ?? __('Not recorded') }}
                                </time>
                            </dd>

                            <dt>Status</dt>
                            <dd>{{ $application->statusLabel() }}</dd>
                        </dl>

                        {{--
                            APP-004: withdrawal is the applicant's action and
                            only while the application is still Submitted. A
                            decided application shows its status and no control,
                            rather than a disabled button that invites the
                            question.
                        --}}
                        @if (in_array((string) $application->id, $withdrawableIds, true))
                            <form method="POST" action="{{ route('applicant-portal.withdraw', ['application' => $application->id]) }}">
                                @csrf
                                <button type="submit">Withdraw this application</button>
                            </form>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('applicant-portal.close') }}" class="applications__close">
            @csrf
            <button type="submit">Close your applications</button>
        </form>

        <p class="help">
            This page is open for a short while and then closes on its own. Ask for a new link whenever you want it
            back.
        </p>

        @include('meridian.footer')
    </main>
</body>
</html>
