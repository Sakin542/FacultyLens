@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#171717;')
@section('preheader')Your AI-generated question drafts are ready.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Question drafts ready'])
    <p style="{{ $p }}">Your AI-generated question drafts are ready.</p>
    @include('emails.components.facts', ['facts' => ['Course' => $course_name ?? null, 'Assessment' => $assessment_name ?? null, 'Drafts' => isset($data['draft_count']) ? (string) $data['draft_count'] : null]])
    <p style="{{ $p }}">Please review and edit the questions before approving them for academic use.</p>
    @include('emails.components.alert', ['tone' => 'warning', 'title' => 'AI generated is not faculty approved', 'body' => 'Drafts are not added to any assessment until a faculty member approves them.'])
    @include('emails.components.button', ['url' => $action_url, 'label' => 'Review Drafts'])
@endsection
