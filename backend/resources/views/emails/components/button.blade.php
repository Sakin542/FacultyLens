{{-- Bulletproof CTA button. Usage: @include('emails.components.button', ['url' => ..., 'label' => ...]) --}}
@php($label = trim((string) ($label ?? 'Open FacultyLens')))
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0 8px 0;">
    <tr>
        <td class="fl-button" align="center" style="border-radius:8px; background-color:#171717;">
            <a href="{{ $url }}" target="_blank" rel="noopener" style="display:inline-block; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:20px; font-weight:600; color:#FFFFFF; text-decoration:none; padding:12px 22px; border-radius:8px; background-color:#171717; border:1px solid #171717;">{{ $label }}</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 4px 0; font-size:12px; line-height:18px; color:#6B6B63;">If the button does not work, copy this link into your browser:<br><a href="{{ $url }}" style="color:#6B6B63; word-break:break-all;">{{ $url }}</a></p>
