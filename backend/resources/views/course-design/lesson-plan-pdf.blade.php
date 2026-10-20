<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Lesson plan — {{ $course->course_code }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.4; color: #1f2937; }
        h1 { font-size: 15pt; margin: 0; color: #1f3d32; }
        .week { border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 8px; margin: 8px 0; page-break-inside: avoid; }
        .week h3 { margin: 0 0 3px; font-size: 10pt; }
        .tag { display: inline-block; border: 1px solid #9ca3af; border-radius: 3px; padding: 0 4px; font-size: 7.5pt; margin-left: 4px; }
        .muted { color: #6b7280; }
        ul { margin: 3px 0 3px 14px; padding: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #d1d5db; padding: 3px 6px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
<h1>{{ $course->course_code }} — {{ $course->course_name }}: lesson plan</h1>
<div class="muted">{{ count($c['weeks'] ?? []) }} weeks · {{ $plan->inputs['sessions_per_week'] ?? 2 }} sessions per week of {{ $plan->inputs['session_minutes'] ?? 75 }} minutes · generated {{ $plan->created_at->format('j M Y') }}</div>

@foreach($c['weeks'] ?? [] as $w)
    <div class="week">
        <h3>Week {{ $w['week'] }}: {{ $w['topic'] }}
            @foreach($w['outcomes'] as $o)<span class="tag">{{ $o }}</span>@endforeach
            @if($w['bloom_focus'])<span class="tag">{{ $w['bloom_focus'] }}</span>@endif
        </h3>
        <ul>
            @foreach($w['sessions'] as $s)
                <li><strong>{{ $s['title'] }}</strong> ({{ $s['minutes'] }} min): {{ $s['description'] }}</li>
            @endforeach
        </ul>
        @if(!empty($w['formative_checks']))<div class="muted">Checks: {{ implode('; ', $w['formative_checks']) }}</div>@endif
        @if(!empty($w['preparation']))<div class="muted">Before class: {{ $w['preparation'] }}</div>@endif
    </div>
@endforeach

<h3>Assessment schedule</h3>
<table>
    <tr><th style="width:44px">Week</th><th>Assessment</th><th>Outcomes</th></tr>
    @foreach($c['assessments'] ?? [] as $a)
        <tr><td>{{ $a['week'] }}</td><td>{{ $a['title'] }}</td><td>{{ implode(', ', $a['outcomes']) }}</td></tr>
    @endforeach
</table>
</body>
</html>
