@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#171717;')
@section('preheader')Your assessment analysis is ready for review.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Assessment analysis completed'])
    <p style="{{ $p }}">Your assessment analysis is ready for review.</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null]])
    <p style="{{ $p }}">FacultyLens completed the requested AI analysis.</p>
    @include('emails.components.alert', ['tone' => 'info', 'title' => 'Faculty review required', 'body' => 'Please review the findings, evidence, and recommendations before making academic decisions. Nothing has been changed automatically.'])
    @include('emails.components.button', ['url' => $action_url, 'label' => 'View Analysis'])
@endsection
