{{-- Common FacultyLens e-mail header: brand name + tagline (no external fonts or images). --}}
<tr>
    <td style="padding: 0 4px 20px 4px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td align="left" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    <a href="{{ $app_url }}" style="text-decoration:none; color:#171717;">
                        <span style="display:inline-block; font-size:22px; line-height:28px; font-weight:700; letter-spacing:-0.3px; color:#171717;">{{ $brand_name }}</span>
                    </a>
                    <div style="font-size:12px; line-height:18px; color:#6B6B63; letter-spacing:0.2px; margin-top:2px;">{{ $brand_tagline }}</div>
                </td>
            </tr>
        </table>
    </td>
</tr>
