{{-- Inline notice. Usage: @include('emails.components.alert', ['tone' => 'info|warning|error|success', 'title' => ..., 'body' => ...]) --}}
@php
    $tone = $tone ?? 'info';
    $palette = [
        'info' => ['bg' => '#F1F2EE', 'border' => '#E2E6E1', 'label' => 'Note', 'accent' => '#4C6B62'],
        'success' => ['bg' => '#EAF4F0', 'border' => '#A9CFC3', 'label' => 'Completed', 'accent' => '#1E6F5C'],
        'warning' => ['bg' => '#FFF1E8', 'border' => '#F3C4A6', 'label' => 'Review recommended', 'accent' => '#9A3412'],
        'error' => ['bg' => '#FEF2F2', 'border' => '#FECACA', 'label' => 'Not completed', 'accent' => '#991B1B'],
    ][$tone] ?? ['bg' => '#F1F2EE', 'border' => '#E2E6E1', 'label' => 'Note', 'accent' => '#4C6B62'];
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">
    <tr>
        <td style="background-color:{{ $palette['bg'] }}; border:1px solid {{ $palette['border'] }}; border-left:4px solid {{ $palette['accent'] }}; border-radius:10px; padding:14px 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:21px; color:#1B2A27;">
            <strong style="display:block; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:{{ $palette['accent'] }}; margin-bottom:4px;">{{ $title ?? $palette['label'] }}</strong>
            {{ $body ?? '' }}
        </td>
    </tr>
</table>
