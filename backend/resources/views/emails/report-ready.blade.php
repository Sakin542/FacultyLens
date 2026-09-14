@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#171717;')
@section('preheader')Your requested report has been generated successfully.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Your report is ready'])
    <p style="{{ $p }}">Your requested report has been generated successfully.</p>
    @include('emails.components.facts', ['facts' => ['Report' => $report_type ?? null, 'Format' => isset($data['format']) ? strtoupper((string) $data['format']) : null]])
    <p style="{{ $p }}">The report is available through your authorized FacultyLens account. For privacy, reports are never attached to email.</p>
    @include('emails.components.button', ['url' => $action_url, 'label' => 'View Report'])
@endsection
