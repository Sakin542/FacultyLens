@extends('emails.layouts.facultylens', ['show_preferences_link' => true])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#1B2A27;')
@section('preheader'){{ $inviter_name ?? 'A colleague' }} invited you to collaborate on FacultyLens.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Collaboration invitation'])
    <p style="{{ $p }}">{{ $inviter_name ?? 'A colleague' }} invited you to collaborate on:</p>
    @include('emails.components.facts', ['facts' => ['Course' => $course_name ?? null, 'Assessment' => $assessment_name ?? null, 'Role' => $role ?? null]])
    <p style="{{ $p }}">Sign in to FacultyLens to accept or decline. Invitations expire automatically.</p>
    @include('emails.components.button', ['url' => $action_url, 'label' => 'View Invitation'])
@endsection
