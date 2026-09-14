@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#171717;')
@section('preheader')Grading consistency review recommended.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Grading consistency review recommended'])
    <p style="{{ $p }}">FacultyLens detected variation between faculty grading results that may require review.</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null]])
    <p style="{{ $p }}">Please review the comparison and supporting evidence.</p>
    @include('emails.components.alert', ['tone' => 'warning', 'title' => 'Neutral comparison', 'body' => 'This is a statistical observation, not a judgement about any grader. Grades are never changed automatically.'])
    @include('emails.components.button', ['url' => $action_url, 'label' => 'Review Grading'])
@endsection
