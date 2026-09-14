@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#1B2A27;')
@section('preheader')Learning outcome review recommended.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Learning outcome review recommended'])
    <p style="{{ $p }}">FacultyLens identified a learning-outcome performance gap that may require review.</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null, 'Outcomes flagged' => isset($data['gap_count']) ? (string) $data['gap_count'] : null]])
    <p style="{{ $p }}">Open FacultyLens to review the aggregated evidence. No individual student information is included in this email.</p>
    @include('emails.components.button', ['url' => $action_url, 'label' => 'Review Gaps'])
@endsection
