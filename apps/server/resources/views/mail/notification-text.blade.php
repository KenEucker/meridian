{{ $notification->senderName() }}

{{ $notification->heading }}

@foreach ($notification->paragraphs as $paragraph)
{{ $paragraph }}

@endforeach
@foreach ($notification->facts as $label => $value)
{{ $label }}: {{ $value }}
@endforeach

{{ $notification->actionLabel }}: {{ $notification->actionUrl }}

You are receiving this because it changes what you can do in Meridian. Signing in is still required; this message grants no access of its own.
