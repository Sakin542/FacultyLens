@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#1B2A27;')
@section('preheader')The requested AI assessment analysis could not be completed.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Assessment analysis failed'])
    <p style="{{ $p }}">The requested AI assessment analysis could not be completed.</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null]])
    @include('emails.components.alert', ['tone' => 'error', 'title' => 'Not completed', 'body' => 'No academic data was automatically changed.'])
    <p style="{{ $p }}">You can retry the analysis from FacultyLens. If the problem persists, contact your administrator.</p>
    @include('emails.components.button', ['url' => $action_url, 'label' => 'Retry Analysis'])
@endsection
