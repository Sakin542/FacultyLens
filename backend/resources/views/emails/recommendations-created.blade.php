@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#1B2A27;')
@section('preheader')FacultyLens identified areas that may require faculty review.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'New assessment recommendations'])
    <p style="{{ $p }}">FacultyLens identified areas that may require faculty review.</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null, 'Recommendations' => isset($data['recommendation_count']) ? (string) $data['recommendation_count'] : null]])
    <p style="{{ $p }}">Open FacultyLens to review the evidence, explanation, and recommendations.</p>
    @include('emails.components.alert', ['tone' => 'info', 'title' => 'Assistive only', 'body' => 'Recommendations are AI-generated suggestions. They do not change your assessment and require your professional judgement.'])
    @include('emails.components.button', ['url' => $action_url, 'label' => 'Review Recommendations'])
@endsection
