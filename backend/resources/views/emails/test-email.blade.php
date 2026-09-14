@extends('emails.layouts.facultylens', ['show_preferences_link' => false])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#171717;')
@section('preheader')FacultyLens test email — delivery pipeline check.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Test email'])
    <p style="{{ $p }}">This message confirms that the FacultyLens email pipeline (queue → worker → mail transport) is working.</p>
    @include('emails.components.facts', ['facts' => ['Requested by' => $requested_by ?? null, 'Environment' => $environment ?? null, 'Sent at' => $sent_at ?? null]])
    @include('emails.components.alert', ['tone' => 'success', 'title' => 'Diagnostics only', 'body' => 'No academic data is involved and no action is required.'])
    @include('emails.components.button', ['url' => $action_url ?? $app_url, 'label' => 'Open FacultyLens'])
@endsection
