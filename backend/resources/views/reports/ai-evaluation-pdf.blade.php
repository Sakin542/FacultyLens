<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>AI Evaluation Report — Run #{{ $report['run']['id'] }}</title>
    <style>
        @page { margin: 35px 40px 45px 40px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10pt; line-height: 1.45; color: #111111; }
        h1 { font-size: 18pt; margin: 0 0 4px; }
        h2 { font-size: 12pt; margin: 18px 0 6px; border-bottom: 1px solid #E5E5E5; padding-bottom: 3px; }
        .muted { color: #737373; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #E5E5E5; padding: 4px 6px; text-align: left; font-size: 9pt; }
        th { background: #F7F7F5; }
        .badge { display: inline-block; padding: 2px 8px; border: 1px solid #111; border-radius: 10px; font-size: 9pt; }
        .pass { background: #ecfdf5; } .warn { background: #fffbeb; } .fail { background: #fef2f2; }
        ul { margin: 4px 0; padding-left: 18px; }
    </style>
</head>
<body>
@php($s = $report['executive_summary'])
<h1>FacultyLens AI Evaluation Report</h1>
<p class="muted">Run #{{ $report['run']['id'] }} · Task {{ $s['task'] }} · Generated {{ $report['generated_at'] }}</p>

<h2>Executive Summary</h2>
<p>
    Status: <span class="badge {{ $s['gate_status'] === 'PASSED' ? 'pass' : ($s['gate_status'] === 'FAILED' ? 'fail' : 'warn') }}">{{ str_replace('_', ' ', $s['gate_status'] ?? $s['status']) }}</span>
    &nbsp; Headline metric <strong>{{ $s['headline_metric'] }}</strong>: <strong>{{ $s['headline_value'] !== null ? number_format($s['headline_value'], 4) : 'n/a' }}</strong>
    &nbsp; Examples: {{ $s['example_count'] }} ({{ str_replace('_', ' ', $s['size_category'] ?? '') }})
</p>
@if(!empty($s['warnings']))<ul>@foreach($s['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>@endif
@if(!empty($s['regression']['previous_run_id']))
    <p class="muted">Previous run #{{ $s['regression']['previous_run_id'] }}: {{ $s['regression']['metric'] }} {{ $s['regression']['previous'] }} → {{ $s['regression']['current'] }}
        @if($s['regression']['regression']) — <strong>Performance regression detected</strong>@endif</p>
@endif

<h2>Model Information</h2>
@if($report['model_information'])
    <table><tr><th>Model</th><td>{{ $report['model_information']['model'] }}</td><th>Provider</th><td>{{ $report['model_information']['provider'] }}</td></tr>
        <tr><th>Type</th><td>{{ $report['model_information']['type'] }}</td><th>Version</th><td>{{ $report['model_information']['version'] }}</td></tr>
        <tr><th>Prompt version</th><td colspan="3">{{ $report['prompt_version']['feature'] ?? '—' }} {{ $report['prompt_version']['version'] ?? '' }}</td></tr></table>
@else<p class="muted">No model registered for this run.</p>@endif

<h2>Dataset Information</h2>
@if($report['dataset_information'])
    <table><tr><th>Name</th><td>{{ $report['dataset_information']['name'] }}</td><th>Version</th><td>{{ $report['dataset_information']['version'] }}</td></tr>
        <tr><th>Source</th><td>{{ $report['dataset_information']['source'] }}</td><th>Split</th><td>{{ $report['dataset_information']['split'] }}</td></tr></table>
@endif

<h2>Evaluation Methodology</h2>
<p>{{ $report['methodology'] }}</p>

<h2>Metrics</h2>
<table><tr><th>Metric</th><th>Value</th></tr>
    @foreach($report['metrics']['scalars'] as $name => $value)<tr><td>{{ $name }}</td><td>{{ number_format($value, 4) }}</td></tr>@endforeach
</table>
@if(!empty($report['metrics']['structured']['confusion_matrix']))
    @php($cm = $report['metrics']['structured']['confusion_matrix'])
    <h2>Confusion Matrix (rows = actual, columns = predicted)</h2>
    <table><tr><th></th>@foreach($cm['labels'] as $l)<th>{{ $l }}</th>@endforeach</tr>
        @foreach($cm['matrix'] as $i => $row)<tr><th>{{ $cm['labels'][$i] }}</th>@foreach($row as $c)<td>{{ $c }}</td>@endforeach</tr>@endforeach</table>
@endif
@if(!empty($report['metrics']['structured']['per_class']))
    <h2>Per-Class Metrics</h2>
    <table><tr><th>Class</th><th>Precision</th><th>Recall</th><th>F1</th><th>Support</th></tr>
        @foreach($report['metrics']['structured']['per_class'] as $pc)<tr><td>{{ $pc['label'] }}</td><td>{{ $pc['precision'] }}</td><td>{{ $pc['recall'] }}</td><td>{{ $pc['f1'] }}</td><td>{{ $pc['support'] }}</td></tr>@endforeach</table>
@endif

<h2>Quality Gates</h2>
<table><tr><th>Metric</th><th>Value</th><th>Target</th><th>Result</th></tr>
    @forelse($report['quality_gates'] as $g)<tr><td>{{ $g['metric'] }}</td><td>{{ $g['value'] ?? 'n/a' }}</td><td>{{ isset($g['min']) ? '≥ '.$g['min'] : (isset($g['max']) ? '≤ '.$g['max'] : '') }}</td><td>{{ $g['passed'] === null ? 'not produced' : ($g['passed'] ? 'PASS' : 'FAIL') }}</td></tr>
    @empty<tr><td colspan="4" class="muted">No gates configured for this task.</td></tr>@endforelse</table>

<h2>Error Analysis</h2>
@if(!empty($report['error_analysis']))<table><tr><th>Error type</th><th>Count</th></tr>@foreach($report['error_analysis'] as $t => $c)<tr><td>{{ $t }}</td><td>{{ $c }}</td></tr>@endforeach</table>
@else<p class="muted">No errors recorded.</p>@endif

<h2>Known Limitations</h2>
<ul>@foreach($report['known_limitations'] as $l)<li>{{ $l }}</li>@endforeach</ul>

<h2>Recommendations</h2>
<ul>@foreach($report['recommendations'] as $r)<li>{{ $r }}</li>@endforeach</ul>

<p class="muted">AI evaluation informs monitoring and analysis only. It does not retrain, re-threshold or deploy models, and it does not establish academic or accreditation validity.</p>
</body>
</html>
