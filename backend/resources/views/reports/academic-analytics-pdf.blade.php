<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>FacultyLens — Academic Analytics</title>
<style>
  body { font-family: DejaVu Sans, Helvetica, sans-serif; font-size: 10.5px; color: #111; margin: 24px; }
  h1 { font-size: 18px; margin: 0 0 4px; } h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
  .muted { color: #666; } .note { background: #f7f7f5; border: 1px solid #e5e5e5; padding: 6px 8px; margin: 8px 0; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; } th, td { border: 1px solid #e5e5e5; padding: 4px 6px; text-align: left; } th { background: #f7f7f5; }
  .kpi { display: inline-block; width: 23%; border: 1px solid #e5e5e5; padding: 6px; margin: 0 1% 6px 0; vertical-align: top; } .kpi b { display: block; font-size: 15px; }
  .num { text-align: right; }
</style>
</head>
<body>
@php $na = fn ($v, $suffix = '') => $v === null ? 'N/A' : $v . $suffix; @endphp
<h1>Academic Analytics</h1>
<p class="muted">Generated {{ \Illuminate\Support\Carbon::parse($a['meta']['generated_at'])->format('F j, Y g:i A') }} for {{ $user->name }} · Scope: {{ count($a['scope']['course_ids']) }} course(s), {{ count($a['scope']['assessment_ids']) }} assessment(s)
@if(!empty($a['filters'])) · Filters: {{ collect($a['filters'])->map(fn ($v, $k) => "$k=$v")->implode(', ') }} @endif</p>
<div class="note">{{ $a['meta']['disclaimer'] }}</div>

<h2>Key indicators</h2>
@foreach($a['kpis'] as $kpi)
  <div class="kpi"><span class="muted">{{ $kpi['label'] }}</span><b>{{ $na($kpi['value'], ($kpi['unit'] ?? '') === 'percent' ? '%' : '') }}</b><span class="muted">{{ $kpi['basis'] ?? '' }}</span></div>
@endforeach

<h2>Assessment quality (STEP 13)</h2>
<p>Analyzed assessments: {{ $a['assessment_quality']['analyzed_assessments'] }} · Average score: {{ $na($a['assessment_quality']['average_score']) }}</p>
<table><tr>@foreach($a['assessment_quality']['counts'] as $r => $c)<th>{{ str_replace('_', ' ', $r) }}</th>@endforeach</tr><tr>@foreach($a['assessment_quality']['counts'] as $c)<td class="num">{{ $c }}</td>@endforeach</tr></table>

<h2>Difficulty distribution</h2>
<p>Balance status: {{ $na($a['difficulty']['balance_status']) }} · Total deviation from target: {{ $na($a['difficulty']['total_deviation'], '%') }}</p>
<table><tr><th>Level</th><th class="num">Questions</th><th class="num">Actual</th><th class="num">Target</th><th class="num">Difference</th></tr>
@foreach($a['difficulty']['distribution'] as $d)<tr><td>{{ ucfirst($d['level']) }}</td><td class="num">{{ $d['count'] }}</td><td class="num">{{ $na($d['percentage'], '%') }}</td><td class="num">{{ $d['target_percentage'] }}%</td><td class="num">{{ $d['difference'] === null ? 'N/A' : ($d['difference'] > 0 ? '+' : '') . $d['difference'] . '%' }}</td></tr>@endforeach</table>

<h2>Cognitive (Bloom) distribution</h2>
<table><tr><th>Level</th><th class="num">Questions</th><th class="num">Share</th></tr>
@foreach($a['cognitive']['distribution'] as $d)<tr><td>{{ $d['level'] }}</td><td class="num">{{ $d['count'] }}</td><td class="num">{{ $na($d['percentage'], '%') }}</td></tr>@endforeach</table>

<h2>Learning-outcome coverage (STEP 11)</h2>
<p>{{ $a['learning_outcomes']['covered_outcomes'] }} of {{ $a['learning_outcomes']['total_outcomes'] }} outcomes covered ({{ $na($a['learning_outcomes']['coverage_percentage'], '%') }})</p>
<table><tr><th>Outcome</th><th>Course</th><th class="num">Questions</th><th class="num">Strong</th><th class="num">Weak</th><th class="num">Not aligned</th><th class="num">Coverage</th><th>Status</th></tr>
@foreach($a['learning_outcomes']['outcomes'] as $o)<tr><td>{{ $o['code'] }}</td><td>{{ $o['course_code'] }}</td><td class="num">{{ $o['questions'] }}</td><td class="num">{{ $o['strong'] }}</td><td class="num">{{ $o['weak'] }}</td><td class="num">{{ $o['not_aligned'] }}</td><td class="num">{{ $na($o['coverage_percentage'], '%') }}</td><td>{{ $o['status'] }}</td></tr>@endforeach</table>

<h2>Program-outcome coverage (STEP 31)</h2>
@if(!($a['program_outcomes']['configured'] ?? false))<p class="muted">{{ $a['program_outcomes']['message'] }}</p>
@else<table><tr><th>PO</th><th>Title</th><th class="num">Mapped COs</th><th class="num">Questions</th><th class="num">Evidence</th><th>Status</th></tr>
@foreach($a['program_outcomes']['program_outcomes'] as $p)<tr><td>{{ $p['code'] }}</td><td>{{ $p['title'] }}</td><td class="num">{{ $p['mapped_cos'] }}</td><td class="num">{{ $p['mapped_questions'] }}</td><td class="num">{{ $na($p['evidence_percent'], '%') }}</td><td>{{ $p['status'] }}</td></tr>@endforeach</table>@endif

<h2>Student performance (finalized faculty grades, STEP 30)</h2>
@if(!$a['performance']['available'])<p class="muted">No finalized grades are available in scope{{ $a['scope']['student_data_restricted'] ? ' (restricted for your role)' : '' }}.</p>
@else<p>Average {{ $a['performance']['average_percentage'] }}% · Median {{ $a['performance']['median_percentage'] }}% · Min {{ $a['performance']['minimum_percentage'] }}% · Max {{ $a['performance']['maximum_percentage'] }}% · {{ $a['performance']['submissions'] }} submissions / {{ $a['performance']['responses'] }} responses · Benchmark {{ $a['performance']['benchmark_percent'] }}% · Status {{ $a['performance']['status'] }}</p>@endif
<table><tr>@foreach($a['learning_gaps']['counts'] as $s => $c)<th>{{ str_replace('_', ' ', $s) }}</th>@endforeach</tr><tr>@foreach($a['learning_gaps']['counts'] as $c)<td class="num">{{ $c }}</td>@endforeach</tr></table>
@if($a['learning_gaps']['top_gaps'])<table><tr><th>Outcome</th><th>Assessment</th><th class="num">Performance</th><th class="num">Benchmark</th><th class="num">Gap</th><th class="num">Responses</th><th>Status</th></tr>
@foreach($a['learning_gaps']['top_gaps'] as $g)<tr><td>{{ $g['code'] }}</td><td>{{ $g['assessment_title'] }}</td><td class="num">{{ $g['average_percentage'] }}%</td><td class="num">{{ $g['benchmark_percent'] }}%</td><td class="num">{{ $g['gap'] }}%</td><td class="num">{{ $g['responses'] }}</td><td>{{ $g['status'] }}</td></tr>@endforeach</table>@endif

<h2>Question similarity (STEP 12)</h2>
<table><tr>@foreach($a['similarity']['by_status'] as $s => $c)<th>{{ str_replace('_', ' ', $s) }}</th>@endforeach</tr><tr>@foreach($a['similarity']['by_status'] as $c)<td class="num">{{ $c['questions'] }} question(s) / {{ $c['matches'] }} match(es)</td>@endforeach</tr></table>

<h2>Rubrics, AI grading assistance and recommendations</h2>
<p>Rubrics: {{ $a['rubrics']['total'] }} total ({{ $a['rubrics']['draft'] }} draft, {{ $a['rubrics']['approved'] }} approved, {{ $a['rubrics']['archived'] }} archived) · Avg criteria {{ $na($a['rubrics']['average_criteria']) }}</p>
<p>AI grading: {{ $a['grading']['ai_assisted_answers'] ?? 0 }} AI-assisted answers · accepted {{ $a['grading']['faculty_accepted'] ?? 0 }} · modified {{ $a['grading']['faculty_modified'] ?? 0 }} · MAE (AI suggestion vs final faculty grade) {{ $na($a['grading']['mae'] ?? null) }}</p>
<p>Recommendations: active {{ $a['recommendations']['active'] }} · accepted {{ $a['recommendations']['accepted'] }} · dismissed {{ $a['recommendations']['dismissed'] }} · under review {{ $a['recommendations']['under_review'] }} · Faculty interaction signal: accepted {{ $na($a['recommendations']['feedback']['accepted_percent'], '%') }}</p>
<p class="muted">{{ $a['inter_grader']['message'] }}</p>

<h2>AI evaluation (STEP 35)</h2>
<table><tr><th>Task</th><th>Metric</th><th class="num">Value</th><th>Gate</th></tr>
@foreach($a['ai_evaluation']['tasks'] as $t)<tr><td>{{ $t['task'] }}</td><td>{{ $t['headline_metric'] }}</td><td class="num">{{ $t['evaluated'] ? $t['headline_value'] : 'Not evaluated yet' }}</td><td>{{ $t['gate_status'] ?? '—' }}</td></tr>@endforeach</table>

<h2>Areas needing attention (signals, not decisions)</h2>
@if(!$a['attention_areas'])<p class="muted">No attention signals in the current scope.</p>
@else<table><tr><th>Severity</th><th>Type</th><th>Signal</th><th>Detail</th></tr>
@foreach($a['attention_areas'] as $s)<tr><td>{{ $s['severity'] }}</td><td>{{ $s['type'] }}</td><td>{{ $s['title'] }}</td><td>{{ $s['detail'] }}</td></tr>@endforeach</table>@endif
</body>
</html>
