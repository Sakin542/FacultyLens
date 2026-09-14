{{-- Common FacultyLens e-mail header: brand emblem (inline CID PNG) + name + tagline. No external fonts. --}}
<tr>
    <td style="padding: 0 4px 20px 4px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td width="56" style="width:56px; vertical-align:middle; padding-right:12px;">
                    <a href="{{ $app_url }}" style="text-decoration:none;">
                        <img src="{{ $logo_src ?? '' }}" width="48" height="48" alt="{{ $brand_name }} logo" style="display:block; width:48px; height:48px; border:0; border-radius:9999px; background-color:#FFFFFF;">
                    </a>
                </td>
                <td align="left" style="vertical-align:middle; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    <a href="{{ $app_url }}" style="text-decoration:none; color:#1B2A27;">
                        <span style="display:inline-block; font-size:22px; line-height:28px; font-weight:700; letter-spacing:-0.3px; color:#1B2A27;">{{ $brand_name }}</span>
                    </a>
                    <div style="font-size:12px; line-height:18px; color:#4C6B62; letter-spacing:0.2px; margin-top:2px;">{{ $brand_tagline }}</div>
                </td>
            </tr>
        </table>
    </td>
</tr>
