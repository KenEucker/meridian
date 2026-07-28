{{--
    God Mode orientation summary (GOD-002, GOD-003, GOD-004; technical spec
    22.5.1). This is Meridian's own landing content and replaces the
    administrative framework's welcome partial.
--}}
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <p class="mb-3">{{ $summary }}</p>

    <div class="alert alert-warning mb-4" role="note">
        <strong>{{ $boundary }}</strong>
    </div>

    <h3 class="h5 mb-3">How Meridian works</h3>

    <dl class="mb-0">
        @foreach ($topics as $topic)
            <dt class="fw-semibold">{{ $topic['heading'] }}</dt>
            <dd class="mb-3">{{ $topic['body'] }}</dd>
        @endforeach
    </dl>
</div>
