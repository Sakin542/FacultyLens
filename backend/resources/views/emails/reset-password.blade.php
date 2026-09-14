@extends('emails.layouts.facultylens', ['show_preferences_link' => false])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#171717;')
@section('preheader')Reset your FacultyLens password.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => 'Reset your password'])
    <p style="{{ $p }}">A password reset was requested for the FacultyLens account associated with this email address.</p>
    <p style="{{ $p }}">Use the button below to choose a new password. This link expires in {{ (int) ($expires_minutes ?? 60) }} minutes and can be used once.</p>
    @include('emails.components.button', ['url' => $reset_url, 'label' => 'Reset Password'])
    @include('emails.components.alert', ['tone' => 'info', 'title' => 'Did not request this?', 'body' => 'If you did not request a password reset, you can safely ignore this email. Your password will not change.'])
    <p style="{{ $p }}">For your security, never share this reset link with anyone. FacultyLens staff will never ask for it.</p>
@endsection
