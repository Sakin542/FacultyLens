@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#1B2A27;')
@section('preheader')You have been assigned to review an assessment.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Review assigned'])
    <p style="{{ $p }}">You have been assigned to review:</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null, 'Version' => $version_label ?? ($data['version_label'] ?? null)]])
    <p style="{{ $p }}">{{ $message ?? 'Open the review in FacultyLens to examine the version, leave comments and record your decision.' }}</p>
    @include('emails.components.button', ['url' => $action_url, 'label' => 'Open Review'])
@endsection
