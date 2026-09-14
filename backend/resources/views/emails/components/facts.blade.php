{{-- Label/value rows. Usage: @include('emails.components.facts', ['facts' => ['Assessment' => 'Midterm', 'Course' => 'CSE-301']]) --}}
@php($facts = array_filter((array) ($facts ?? []), fn ($v) => $v !== null && trim((string) $v) !== ''))
@if($facts !== [])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 18px 0 6px 0; border-top:1px solid #E2E6E1;">
    @foreach($facts as $label => $value)
    <tr>
        <td style="padding:10px 0; border-bottom:1px solid #E2E6E1; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:12px; line-height:18px; color:#4C6B62; text-transform:uppercase; letter-spacing:0.4px; width:34%; vertical-align:top;">{{ $label }}</td>
        <td style="padding:10px 0; border-bottom:1px solid #E2E6E1; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:20px; color:#1B2A27; vertical-align:top;">{{ $value }}</td>
    </tr>
    @endforeach
</table>
@endif
