{{-- Shared typography partials as small blade snippets --}}
@php($greeting = !empty($recipient_name) ? 'Hello ' . $recipient_name . ',' : 'Hello,')
<h1 class="fl-h1" style="margin:0 0 6px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:22px; line-height:30px; font-weight:700; color:#171717;">{{ $heading }}</h1>
<p style="margin:0 0 18px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:22px; color:#6B6B63;">{{ $greeting }}</p>
