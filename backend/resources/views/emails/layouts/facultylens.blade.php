<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject ?? $brand_name }}</title>
    <style>
        /* Progressive enhancement only — every critical style is also inline for Gmail / Outlook. */
        body { margin: 0; padding: 0; background-color: #F7F4EE; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
        img { border: 0; line-height: 100%; outline: none; text-decoration: none; }
        a { color: #171717; }
        .fl-wrapper { width: 100%; background-color: #F7F4EE; }
        .fl-container { width: 600px; max-width: 600px; }
        .fl-card { background-color: #FFFFFF; border: 1px solid #E7E2D8; border-radius: 12px; }
        .fl-button a { display: inline-block; background-color: #171717; color: #FFFFFF !important; text-decoration: none; font-weight: 600; padding: 12px 22px; border-radius: 8px; }
        @media only screen and (max-width: 620px) {
            .fl-container { width: 100% !important; max-width: 100% !important; }
            .fl-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .fl-h1 { font-size: 20px !important; line-height: 28px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#F7F4EE; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#171717;">
@hasSection('preheader')
    <div data-plain-text="skip" style="display:none; font-size:1px; color:#F7F4EE; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">@yield('preheader')</div>
@endif
<table role="presentation" class="fl-wrapper" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; background-color:#F7F4EE;">
    <tr>
        <td align="center" style="padding: 32px 16px;">
            <table role="presentation" class="fl-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
                @include('emails.components.header')
                <tr>
                    <td>
                        <table role="presentation" class="fl-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; background-color:#FFFFFF; border:1px solid #E7E2D8; border-radius:12px;">
                            <tr>
                                <td class="fl-pad" style="padding: 32px 36px 28px 36px;">
                                    @yield('content')
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @include('emails.components.footer')
            </table>
        </td>
    </tr>
</table>
</body>
</html>
