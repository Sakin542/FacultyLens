<!-- SECTION 8: CO/PO MAPPING ANALYSIS (STEP 31, course-level review signals; not an accreditation decision) -->
<div class="avoid-break" style="margin-top: 14px;">
    <h2>8. CO/PO Mapping Analysis</h2>
    <p style="font-size: 8.5pt; color: #475569;">
        Program: <strong>{{ $cp['program'] ?? 'Not assigned' }}</strong> &bull; COs: <strong>{{ $cp['summary']['co_count'] }}</strong> &bull; POs: <strong>{{ $cp['summary']['po_count'] }}</strong>
        &bull; Active mappings: <strong>{{ $cp['summary']['active_mapping_count'] }} / {{ $cp['summary']['possible_mapping_count'] }}</strong> (density {{ $cp['summary']['mapping_density_percent'] }}%)
        &bull; Questions mapped: <strong>{{ $cp['summary']['questions_mapped'] }} / {{ $cp['summary']['question_count'] }}</strong>
        &bull; Analyzed {{ $cp['analyzed_at'] }}{{ $cp['is_stale'] ? ' (may be outdated)' : '' }}
    </p>

    @if(!empty($cp['matrix']['rows']))
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 14%;">CO \ PO</th>
                @foreach($cp['matrix']['program_outcomes'] as $po)<th class="text-center">{{ $po['code'] }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($cp['matrix']['rows'] as $row)
            <tr>
                <td><strong>{{ $row['code'] }}</strong></td>
                @foreach($row['cells'] as $cell)<td class="text-center">{{ $cell['level'] > 0 ? $cell['level'] : '—' }}</td>@endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
    <p style="font-size: 7.5pt; color: #64748b;">Legend: — none &bull; 1 low &bull; 2 medium &bull; 3 high</p>
    @else
    <p class="text-muted">No CO→PO matrix is available (no program outcomes configured).</p>
    @endif

    <table style="width:100%; margin-top: 8px; font-size: 8.5pt;">
        <tr>
            <td style="width:50%; vertical-align:top; padding-right: 8px;">
                <strong>CO Assessment Coverage &amp; Performance</strong>
                @foreach($cp['co_coverage'] as $co)
                <div>{{ $co['display_code'] }} — coverage {{ $co['coverage_percent'] }}% — performance {{ $co['performance_percent'] !== null ? $co['performance_percent'] . '%' : 'N/A' }} — {{ ucwords(strtolower(str_replace('_', ' ', $co['status']))) }}</div>
                @endforeach
            </td>
            <td style="width:50%; vertical-align:top;">
                <strong>PO Evidence</strong>
                @forelse($cp['po_evidence'] as $po)
                <div>{{ $po['code'] }} — CO evidence {{ $po['co_evidence'] }} — assessment {{ $po['assessment_evidence_percent'] }}% — student {{ $po['student_performance_percent'] !== null ? $po['student_performance_percent'] . '%' : 'N/A' }} — {{ ucwords(strtolower(str_replace('_', ' ', $po['status']))) }}</div>
                @empty
                <div class="text-muted">No program outcomes configured.</div>
                @endforelse
            </td>
        </tr>
    </table>

    <div style="margin-top: 8px; font-size: 8.5pt;">
        <strong>Mapping Findings</strong>
        @forelse($cp['findings'] as $f)
        <div>&bull; [{{ $f['severity'] }}] {{ $f['title'] }} <span style="color:#64748b;">— {{ $f['recommendation'] }}</span></div>
        @empty
        <div class="text-muted">No mapping review signals were generated.</div>
        @endforelse
    </div>
    <p style="font-size: 7.5pt; color: #64748b; margin-top: 6px;"><em>{{ $cp['disclaimer'] }}</em></p>
</div>
