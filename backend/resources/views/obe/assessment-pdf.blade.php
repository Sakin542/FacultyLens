@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $course->course_code }} — {{ $assessment->title }}</title>
    <style>
        @page { margin: 40px 46px; }
        body { font-family: 'DejaVu Serif', serif; font-size: 10.5pt; line-height: 1.45; color: #111; }
        .head { text-align: center; border-bottom: 1.5px solid #111; padding-bottom: 6px; margin-bottom: 12px; }
        .q { margin-bottom: 10px; page-break-inside: avoid; }
        .meta { font-size: 8.5pt; color: #555; }
        .answer { margin: 4px 0 0 22px; padding: 4px 8px; border-left: 2px solid #999; font-size: 9.5pt; color: #333; }
    </style>
</head>
<body>
<div class="head">
    <strong style="font-size:13pt">{{ $course->course_code }} — {{ $course->course_name }}</strong><br>
    {{ $assessment->title }} · Full marks {{ $fmt($questions->sum('marks')) }}{{ $assessment->duration_minutes ? ' · '.$assessment->duration_minutes.' minutes' : '' }}
</div>
@forelse($questions as $q)
    <div class="q">
        <strong>{{ $q->question_number }}.</strong> {!! nl2br(e($q->question_text)) !!} <strong style="float:right">[{{ $fmt($q->marks) }}]</strong>
        <div class="meta">{{ $q->learningOutcome?->code ?? 'No CO' }} · {{ $q->cognitive_level ?: ($q->ai_cognitive_level ?: 'Bloom level not set') }} · {{ $q->difficulty_level ?: 'difficulty not set' }}</div>
        @if($q->expected_answer)<div class="answer"><strong>Expected answer:</strong> {!! nl2br(e($q->expected_answer)) !!}</div>@endif
    </div>
@empty
    <p>No questions recorded for this assessment.</p>
@endforelse
</body>
</html>
