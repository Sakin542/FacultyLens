@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#1B2A27;')
@section('preheader')A draft rubric has been generated for your question.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Rubric draft ready'])
    <p style="{{ $p }}">A draft rubric has been generated for your question.</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null, 'Draft version' => isset($data['version']) ? 'v' . $data['version'] : null]])
    @include('emails.components.alert', ['tone' => 'warning', 'title' => 'AI-generated draft', 'body' => 'The rubric is an AI-generated draft and requires faculty review before use. It is not applied to any grading until you approve it.'])
    @include('emails.components.button', ['url' => $action_url, 'label' => 'Review Rubric'])
@endsection
