@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#171717;')
@section('preheader')Student performance analysis is ready for review.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Performance analysis ready'])
    <p style="{{ $p }}">Student performance analysis is ready for review.</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null]])
    <p style="{{ $p }}">The analysis uses finalized faculty grading data according to FacultyLens academic workflow rules. Results are aggregated; individual student details are available only inside your authorized FacultyLens account.</p>
    @include('emails.components.button', ['url' => $action_url, 'label' => 'Open Analysis'])
@endsection
