<!-- SECTION 9: ASSESSMENT BLUEPRINT (STEP 37, planning layer; comparison is informational and never changes questions) -->
<div class="avoid-break" style="margin-top: 14px;">
    <h2>9. Assessment Blueprint</h2>
    <p style="font-size: 8.5pt; color: #475569;">
        Version <strong>{{ $bp['version'] }}</strong> &bull; Status <strong>{{ $bp['status'] }}</strong> &bull; Validation <strong>{{ $bp['validation_status'] ?? 'Not validated' }}</strong>
        &bull; Planned: <strong>{{ $bp['total_questions'] }}</strong> questions / <strong>{{ $bp['total_marks'] }}</strong> marks{{ $bp['duration_minutes'] ? ' / ' . $bp['duration_minutes'] . ' min' : '' }}
        &bull; Blueprint Completeness <strong>{{ $bp['completeness'] }}%</strong> (planning indicator, not assessment quality)
    </p>

    @if(!empty($bp['sections']))
    <table class="data-table">
        <thead><tr><th>Section</th><th>Type</th><th class="text-center">Questions</th><th class="text-center">Marks each</th><th class="text-center">Total</th></tr></thead>
        <tbody>
            @foreach($bp['sections'] as $s)
            <tr><td><strong>{{ $s['title'] }}</strong></td><td>{{ $s['question_type'] ? ucwords(str_replace('_', ' ', $s['question_type'])) : '—' }}</td><td class="text-center">{{ $s['question_count'] }}</td><td class="text-center">{{ $s['marks_per_question'] }}</td><td class="text-center">{{ $s['total_marks'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @php $cmp = $bp['comparison']; @endphp
    <p style="font-size: 8.5pt; margin-top: 8px;">
        Blueprint vs actual question set: <strong>{{ $cmp['compliance_percent'] === null ? 'N/A (no questions yet)' : $cmp['compliance_percent'] . '% compliance' }}</strong> (tolerance ±{{ $cmp['tolerance_percent'] }}%)
    </p>
    <table class="data-table">
        <thead><tr><th>Dimension</th><th>Item</th><th class="text-center">Target</th><th class="text-center">Actual</th><th class="text-center">Difference</th><th class="text-center">Status</th></tr></thead>
        <tbody>
            @foreach(['difficulty' => 'Difficulty', 'cognitive' => 'Bloom', 'learning_outcomes' => 'CO', 'program_outcomes' => 'PO', 'topics' => 'Topic', 'question_types' => 'Question type'] as $dim => $label)
                @foreach(($cmp['dimensions'][$dim]['rows'] ?? []) as $r)
                    @if($r['status'] !== 'NOT_CONFIGURED')
                    <tr><td>{{ $label }}</td><td>{{ $r['label'] }}</td><td class="text-center">{{ $r['target_percentage'] }}%</td><td class="text-center">{{ $r['actual_percentage'] === null ? '—' : $r['actual_percentage'] . '%' }}</td><td class="text-center">{{ $r['difference'] === null ? '—' : ($r['difference'] > 0 ? '+' : '') . $r['difference'] . '%' }}</td><td class="text-center">{{ $r['status'] }}</td></tr>
                    @endif
                @endforeach
            @endforeach
        </tbody>
    </table>

    @if(!empty($bp['warnings']))
    <p style="font-size: 8.5pt; margin-top: 6px;"><strong>Blueprint warnings</strong></p>
    <ul style="font-size: 8pt; color: #475569; margin: 2px 0 0 14px;">
        @foreach($bp['warnings'] as $w)<li>{{ $w['message'] }}</li>@endforeach
    </ul>
    @endif
</div>
