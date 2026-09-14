@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#171717;')
@section('preheader'){{ $message ?? $title ?? '' }}@endsection
@section('content')
    @include('emails.components.heading', ['heading' => $title ?? 'FacultyLens notification'])
    <p style="{{ $p }}">{{ $message ?? '' }}</p>
    @include('emails.components.facts', ['facts' => ['Assessment' => $assessment_name ?? null, 'Course' => $course_name ?? null]])
    <p style="{{ $p }}">Open FacultyLens to review the details through your authorized account.</p>
    @include('emails.components.button', ['url' => $action_url, 'label' => $action_label ?? 'Open FacultyLens'])
@endsection
