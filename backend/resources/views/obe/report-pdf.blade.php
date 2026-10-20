@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>OBE evidence — {{ $course->course_code }}</title>
    <style>
        @page { margin: 36px 40px 46px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.4; color: #1f2937; }
        h1 { font-size: 15pt; margin: 0 0 2px; color: #1f3d32; }
        h2 { font-size: 11.5pt; margin: 18px 0 6px; color: #1f3d32; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        h3 { font-size: 10pt; margin: 12px 0 4px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin: 4px 0 8px; }
        th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        td.num, th.num { text-align: center; }
        .yes { color: #166534; font-weight: bold; }
        .no { color: #991b1b; font-weight: bold; }
        .sample { border-left: 3px solid #9ca3af; padding: 3px 8px; margin: 3px 0 6px; background: #f9fafb; }
        .footer { position: fixed; bottom: -28px; left: 0; right: 0; font-size: 7.5pt; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
<div class="footer">{{ $course->course_code }} · OBE evidence · generated {{ $generated_at->format('j M Y H:i') }} by FacultyLens</div>

<h1>{{ $course->course_code }} — {{ $course->course_name }}</h1>
<div class="muted">{{ trim($course->semester.' '.$course->academic_year) }} · Instructor: {{ $course->user?->name ?? '—' }}{{ $course->user?->department ? ' · '.$course->user->department : '' }}</div>

<h2>1. Course outcome (CO) attainment</h2>
<p class="muted">Direct attainment from graded work: marks-weighted class average on the questions mapped to each CO. Target {{ $fmt($co_target) }}%. Levels: @foreach($levels as $l)level {{ $l[1] }} ≥ {{ $l[0] }}%@if(!$loop->last), @endif @endforeach.</p>
<table>
    <tr><th>CO</th><th>Description</th><th class="num">Class average</th><th class="num">Level</th><th class="num">Attained</th></tr>
    @forelse($co_attainment as $c)
        <tr>
            <td><strong>{{ $c['code'] }}</strong></td>
            <td>{{ $c['description'] }}</td>
            <td class="num">{{ $c['average_percent'] === null ? 'not assessed' : $fmt($c['average_percent']).'%' }}</td>
            <td class="num">{{ $c['level'] ?? '—' }}</td>
            <td class="num">@if(!$c['assessed'])<span class="muted">—</span>@elseif($c['attained'])<span class="yes">Yes</span>@else<span class="no">No</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No course outcomes defined.</td></tr>
    @endforelse
</table>

<h2>2. CO → PO mapping matrix</h2>
@if($program_outcomes->isEmpty())
    <p class="muted">No CO–PO mappings have been recorded for this course yet (CO/PO Mapping page).</p>
@else
    <table>
        <tr><th>CO</th>@foreach($program_outcomes as $p)<th class="num">{{ $p->code }}</th>@endforeach</tr>
        @foreach($matrix as $row)
            <tr><td><strong>{{ $row['code'] }}</strong></td>@foreach($row['levels'] as $level)<td class="num">{{ $level ?: '' }}</td>@endforeach</tr>
        @endforeach
    </table>
    <p class="muted">1 = low, 2 = medium, 3 = high correlation.</p>

    <h2>3. Program outcome (PO) attainment</h2>
    <p class="muted">PO attainment = Σ(CO level × mapping strength) ÷ Σ(mapping strength). Target {{ $fmt($po_target) }} on the 0–3 scale.</p>
    <table>
        <tr><th>PO</th><th>Title</th><th class="num">Attainment (0–3)</th><th class="num">Attained</th><th>From</th></tr>
        @foreach($po_attainment as $p)
            <tr>
                <td><strong>{{ $p['code'] }}</strong></td><td>{{ $p['title'] }}</td>
                <td class="num">{{ $fmt($p['attainment']) }}</td>
                <td class="num">@if($p['attainment'] === null)<span class="muted">—</span>@elseif($p['attained'])<span class="yes">Yes</span>@else<span class="no">No</span>@endif</td>
                <td>{{ implode(', ', $p['contributing_cos']) ?: '—' }}</td>
            </tr>
        @endforeach
    </table>
@endif

<h2>4. Assessment evidence</h2>
@forelse($evidence as $e)
    <h3>{{ $e['assessment']->title }} <span class="muted">({{ $e['assessment']->type }}{{ $e['assessment']->assessment_date ? ', '.\Illuminate\Support\Carbon::parse($e['assessment']->assessment_date)->format('j M Y') : '' }})</span></h3>
    @if(count($e['questions']) === 0)
        <p class="muted">No questions recorded.</p>
    @else
        <table>
            <tr><th style="width:30px">Q</th><th>Question</th><th class="num" style="width:44px">Marks</th><th class="num" style="width:40px">CO</th><th class="num" style="width:70px">Bloom</th></tr>
            @foreach($e['questions'] as $q)
                <tr>
                    <td>{{ $q['number'] }}</td>
                    <td>{{ $q['text'] }}
                        @foreach($q['samples'] as $s)
                            <div class="sample"><strong>{{ $s['label'] }} sample · {{ $fmt($s['marks']) }} marks:</strong> {{ $s['text'] }}</div>
                        @endforeach
                    </td>
                    <td class="num">{{ $fmt($q['marks']) }}</td><td class="num">{{ $q['co'] ?? '—' }}</td><td class="num">{{ $q['bloom'] ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@empty
    <p class="muted">No assessments recorded for this offering.</p>
@endforelse
@if(!$include_samples)<p class="muted">Sample answers are omitted: they require student data access on this course.</p>@endif
</body>
</html>
