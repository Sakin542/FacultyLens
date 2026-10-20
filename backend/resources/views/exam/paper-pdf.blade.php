@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $course->course_code }} — {{ $paper->title }} (Set {{ $paper->set_label }})</title>
    <style>
        @page { margin: 40px 48px 50px 48px; }
        body { font-family: 'DejaVu Serif', 'Times New Roman', serif; font-size: 11pt; line-height: 1.45; color: #111; }
        .head { text-align: center; border-bottom: 1.5px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
        .course { font-size: 14pt; font-weight: bold; }
        .title { font-size: 12pt; font-weight: bold; margin-top: 2px; }
        .set { display: inline-block; border: 1px solid #111; padding: 0 6px; margin-left: 6px; font-size: 10pt; }
        .meta { font-size: 10pt; margin-top: 4px; }
        .meta td { padding: 0 18px; }
        .instructions { font-style: italic; font-size: 10pt; margin: 8px 0 14px; }
        .section { font-weight: bold; margin: 14px 0 6px; text-transform: none; }
        table.q { width: 100%; border-collapse: collapse; margin-bottom: 9px; page-break-inside: avoid; }
        table.q td { vertical-align: top; padding: 0; }
        td.num { width: 26px; font-weight: bold; }
        td.marks { width: 44px; text-align: right; font-weight: bold; }
        .option { margin: 2px 0 0 4px; font-size: 10.5pt; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; text-align: center; font-size: 8pt; color: #666; }
        .key-title { text-align: center; font-size: 13pt; font-weight: bold; margin-bottom: 4px; }
        .key-note { text-align: center; font-size: 9pt; color: #555; margin-bottom: 12px; }
        .key-item { margin-bottom: 8px; page-break-inside: avoid; }
        .review { color: #9a3412; font-size: 9pt; }
        .missing { color: #777; font-style: italic; }
    </style>
</head>
<body>
<div class="footer">{{ $course->course_code }} · {{ $paper->title }} · Set {{ $paper->set_label }}</div>

<div class="head">
    <div class="course">{{ $course->course_code }} — {{ $course->course_name }}</div>
    <div class="title">{{ $paper->title }} <span class="set">SET {{ $paper->set_label }}</span></div>
    <table class="meta" align="center"><tr>
        @if($paper->duration_minutes)<td>Time: {{ $paper->duration_minutes }} minutes</td>@endif
        <td>Full marks: {{ $fmt($paper->total_marks) }}</td>
        @if($course->semester || $course->academic_year)<td>{{ trim($course->semester.' '.$course->academic_year) }}</td>@endif
    </tr></table>
</div>

@if($paper->instructions)
    <div class="instructions">{{ $paper->instructions }}</div>
@endif

@foreach($sections as $section)
    @if($section['title'])<div class="section">{{ $section['title'] }}</div>@endif
    @foreach($section['items'] as $row)
        <table class="q"><tr>
            <td class="num">{{ $row['number'] }}.</td>
            <td>
                {!! nl2br(e($row['item']->question_text)) !!}
                @foreach((array) $row['item']->options as $i => $option)
                    <div class="option">({{ chr(97 + $i) }}) {{ is_array($option) ? ($option['text'] ?? json_encode($option)) : $option }}</div>
                @endforeach
            </td>
            <td class="marks">[{{ $fmt($row['item']->marks) }}]</td>
        </tr></table>
    @endforeach
@endforeach

@if($answerKey)
    <div style="page-break-before: always;"></div>
    <div class="key-title">Answer key — Set {{ $paper->set_label }}</div>
    <div class="key-note">Confidential: for examiners only.</div>
    @foreach($sections as $section)
        @foreach($section['items'] as $row)
            <div class="key-item">
                <strong>{{ $row['number'] }}.</strong>
                @if(trim((string) $row['item']->expected_answer) !== '')
                    {!! nl2br(e($row['item']->expected_answer)) !!}
                @else
                    <span class="missing">No expected answer recorded.</span>
                @endif
                <span style="float:right">[{{ $fmt($row['item']->marks) }}]</span>
                @if($row['item']->answer_needs_review)
                    <div class="review">⚠ Recompute: this variant changed values used in the answer.</div>
                @endif
            </div>
        @endforeach
    @endforeach
@endif
</body>
</html>
