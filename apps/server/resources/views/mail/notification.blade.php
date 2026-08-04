{{--
    The transactional notification email (M18.21; NOTIFY-003, NOTIFY-004;
    BRAND-002).

    Table layout with inline styles, because mail clients are not browsers:
    external stylesheets are stripped, flexbox and grid are unreliable, and a
    <style> block survives in some clients and not others. Everything that must
    render is inline on the element it applies to.

    Colors come from the organization's palette where it chose one and from
    Meridian's default where it did not, which is the distinction
    BrandingProfile draws everywhere else. The mark is the organization's
    compact mark, falling back to its full lockup and then to the generated
    lettermark BRAND-005 asks for — a remote image every recipient's client is
    entitled to refuse, which is why the lettermark is not merely a fallback for
    an organization with no logo but the thing that renders whenever the image
    does not.
--}}
@php
    $palette = $notification->palette;
    $canvas = $palette->canvas()->hex;
    $surface = $palette->surface()->hex;
    $foreground = $palette->foreground()->hex;
    $muted = $palette->mutedForeground()->hex;
    $border = $palette->border()->hex;
    $primary = $palette->primary()->hex;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $notification->subject }}</title>
</head>
<body style="margin:0; padding:0; background-color:{{ $canvas }}; color:{{ $foreground }}; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:16px; line-height:1.5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $canvas }}; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; background-color:{{ $surface }}; border:1px solid {{ $border }}; border-radius:8px;">
                <tr>
                    <td style="padding:24px 24px 8px 24px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                @if ($notification->markUrl !== null)
                                    <td style="padding-right:12px; vertical-align:middle;">
                                        <img src="{{ $notification->markUrl }}" alt="{{ $notification->senderName() }}" width="40" height="40" style="display:block; width:40px; height:40px; border:0; border-radius:6px;">
                                    </td>
                                @else
                                    <td style="padding-right:12px; vertical-align:middle;">
                                        <div style="width:40px; height:40px; border-radius:6px; background-color:{{ $primary }}; color:{{ $surface }}; text-align:center; line-height:40px; font-weight:700; letter-spacing:0.5px;">{{ $notification->lettermark }}</div>
                                    </td>
                                @endif
                                <td style="vertical-align:middle; font-weight:600; color:{{ $foreground }};">{{ $notification->senderName() }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 24px 0 24px;">
                        <h1 style="margin:0 0 12px 0; font-size:20px; line-height:1.3; color:{{ $foreground }};">{{ $notification->heading }}</h1>
                        @foreach ($notification->paragraphs as $paragraph)
                            <p style="margin:0 0 12px 0; color:{{ $foreground }};">{{ $paragraph }}</p>
                        @endforeach
                    </td>
                </tr>
                @if ($notification->facts !== [])
                    <tr>
                        <td style="padding:4px 24px 0 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid {{ $border }}; margin-top:8px;">
                                @foreach ($notification->facts as $label => $value)
                                    <tr>
                                        <td style="padding:8px 12px 8px 0; color:{{ $muted }}; font-size:14px; white-space:nowrap; vertical-align:top;">{{ $label }}</td>
                                        <td style="padding:8px 0; color:{{ $foreground }}; font-size:14px; vertical-align:top;">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:20px 24px 24px 24px;">
                        <a href="{{ $notification->actionUrl }}" style="display:inline-block; padding:10px 18px; background-color:{{ $primary }}; color:{{ $surface }}; text-decoration:none; border-radius:6px; font-weight:600;">{{ $notification->actionLabel }}</a>
                        <p style="margin:12px 0 0 0; color:{{ $muted }}; font-size:13px;">Or open {{ $notification->actionUrl }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 24px 24px; border-top:1px solid {{ $border }};">
                        <p style="margin:16px 0 0 0; color:{{ $muted }}; font-size:13px;">
                            You are receiving this because it changes what you can do in Meridian. Signing in is still required; this message grants no access of its own.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
