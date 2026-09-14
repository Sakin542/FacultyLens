{{-- Inline notice. Usage: @include('emails.components.alert', ['tone' => 'info|warning|error|success', 'title' => ..., 'body' => ...]) --}}
@php
    $tone = $tone ?? 'info';
    $palette = [
        'info' => ['bg' => '#F7F4EE', 'border' => '#E7E2D8', 'label' => 'Note'],
        'success' => ['bg' => '#F1F6F1', 'border' => '#CFE0CF', 'label' => 'Completed'],
        'warning' => ['bg' => '#FBF6EA', 'border' => '#EAD9A6', 'label' => 'Review recommended'],
        'error' => ['bg' => '#FBF0EE', 'border' => '#E8C4BC', 'label' => 'Not completed'],
    ][$tone] ?? ['bg' => '#F7F4EE', 'border' => '#E7E2D8', 'label' => 'Note'];
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">
    <tr>
        <td style="background-color:{{ $palette['bg'] }}; border:1px solid {{ $palette['border'] }}; border-radius:8px; padding:14px 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:21px; color:#171717;">
            <strong style="display:block; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#6B6B63; margin-bottom:4px;">{{ $title ?? $palette['label'] }}</strong>
            {{ $body ?? '' }}
        </td>
    </tr>
</table>
