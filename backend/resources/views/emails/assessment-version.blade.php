@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#1B2A27;')
@php($status = strtoupper((string) ($data['status'] ?? '')))
@php($label = $version_label ?? ($data['version_label'] ?? null))
@section('preheader'){{ $title ?? 'Assessment version update' }}@endsection
@section('content')
    @include('emails.components.heading', ['heading' => $title ?? 'Assessment version update'])
    <p style="{{ $p }}">{{ $message ?? '' }}</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null, 'Version' => $label, 'Status' => $status !== '' ? ucfirst(strtolower($status)) : null]])
    @if($status === 'FINALIZED')
        @include('emails.components.alert', ['tone' => 'success', 'title' => 'Finalized', 'body' => 'Assessment version ' . ($label ?? '') . ' has been finalized and is now locked for editing. This email is a notice only — the finalization was performed in FacultyLens by an authorized faculty member.'])
    @elseif($status === 'APPROVED')
        @include('emails.components.alert', ['tone' => 'success', 'title' => 'Approved', 'body' => 'The version was approved in FacultyLens. Finalization is a separate faculty action.'])
    @endif
    @include('emails.components.button', ['url' => $action_url, 'label' => $action_label ?? 'Open Version'])
@endsection
