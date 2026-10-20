<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Syllabus — {{ $course->course_code }}</title>
    <style>
        @page { margin: 38px 42px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.45; color: #1f2937; }
        h1 { font-size: 15pt; margin: 0; color: #1f3d32; }
        h2 { font-size: 11pt; margin: 16px 0 6px; color: #1f3d32; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .muted { color: #6b7280; }
        .note { font-size: 8pt; color: #6b7280; margin-top: 18px; }
    </style>
</head>
<body>
<h1>{{ $course->course_code }} — {{ $course->course_name }}</h1>
<div class="muted">{{ trim($course->semester.' '.$course->academic_year) }} · {{ $course->credits }} credits · Syllabus draft generated {{ $plan->created_at->format('j M Y') }}</div>

@if(!empty($plan->inputs['description']))
    <h2>Course description</h2>
    <p>{{ $plan->inputs['description'] }}</p>
@endif

<h2>Course outcomes</h2>
<table>
    <tr><th style="width:44px">CO</th><th>On completing this course, students will be able to…</th><th style="width:80px">Bloom level</th></tr>
    @foreach($c['outcomes'] ?? [] as $o)
        <tr><td><strong>{{ $o['code'] }}</strong></td><td>{{ $o['statement'] }}</td><td>{{ $o['cognitive_level'] }}</td></tr>
    @endforeach
</table>

@if(!empty($c['co_po']))
    <h2>Suggested CO → PO mapping</h2>
    <table>
        <tr><th style="width:44px">CO</th><th style="width:44px">PO</th><th style="width:50px">Level</th><th>Evidence</th></tr>
        @foreach($c['co_po'] as $l)
            <tr><td>{{ $l['co'] }}</td><td>{{ $l['po'] }}</td><td>{{ $l['level'] }}</td><td class="muted">{{ $l['justification'] }}</td></tr>
        @endforeach
    </table>
@endif

<h2>Weekly outline</h2>
<table>
    <tr><th style="width:44px">Week</th><th>Topic</th><th style="width:90px">Outcomes</th></tr>
    @foreach($c['weekly_outline'] ?? [] as $w)
        <tr><td>{{ $w['week'] }}</td><td>{{ $w['topic'] }}</td><td>{{ implode(', ', $w['outcomes']) }}</td></tr>
    @endforeach
</table>

<h2>Assessment plan</h2>
<table>
    <tr><th>Component</th><th style="width:60px">Weight</th><th style="width:140px">Outcomes</th></tr>
    @foreach($c['assessment_plan'] ?? [] as $a)
        <tr><td>{{ $a['component'] }}</td><td>{{ $a['weight'] }}%</td><td>{{ implode(', ', $a['outcomes']) }}</td></tr>
    @endforeach
</table>

<p class="note">Draft produced by FacultyLens ({{ ($c['engine'] ?? 'template') === 'model' ? 'generation model' : 'template engine' }}; CO→PO links by {{ $c['co_po_method'] ?? 'n/a' }} similarity). Review and edit before adopting it as the official syllabus.</p>
</body>
</html>
