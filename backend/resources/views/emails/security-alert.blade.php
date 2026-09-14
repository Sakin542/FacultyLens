@extends('emails.layouts.facultylens', ['show_preferences_link' => false])
@php($p = 'margin:0 0 14px 0; font-size:15px; line-height:24px; color:#1B2A27;')
@section('preheader')Important account security notice.@endsection
@section('content')
    @include('emails.components.heading', ['heading' => $title ?? 'Security alert'])
    @include('emails.components.alert', ['tone' => 'warning', 'title' => 'Account security', 'body' => $message ?? 'A security-relevant event occurred on your FacultyLens account.'])
    <p style="{{ $p }}">If you recognise this activity, no action is needed. If you do not, sign in and change your password, or use “Forgot password” on the sign-in page.</p>
    <p style="{{ $p }}">FacultyLens will never ask you for your password by email.</p>
    @include('emails.components.button', ['url' => $action_url, 'label' => $action_label ?? 'Review Account'])
@endsection
