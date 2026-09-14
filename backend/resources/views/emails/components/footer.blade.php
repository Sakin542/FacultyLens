{{-- Common FacultyLens e-mail footer. No secrets, internal URLs or technical detail. --}}
<tr>
    <td style="padding: 20px 8px 0 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:12px; line-height:18px; color:#4C6B62;">
        <p style="margin:0 0 8px 0;">You are receiving this email because of activity associated with your {{ $brand_name }} account.</p>
        @if(!empty($show_preferences_link))
            <p style="margin:0 0 8px 0;"><a href="{{ $preferences_url }}" style="color:#1E6F5C; text-decoration:underline;">Manage notification preferences</a> (sign-in required)</p>
        @endif
        <p style="margin:0 0 8px 0;">{{ $brand_name }} provides AI-assisted analysis to support faculty judgement. It does not make or record academic decisions on your behalf.</p>
        <p style="margin:0; color:#5F7D74;">&copy; {{ $year }} {{ $brand_name }}</p>
    </td>
</tr>
