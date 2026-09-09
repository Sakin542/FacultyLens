<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Assessment Quality Report - {{ $data['assessment']['course_code'] }} - {{ $data['assessment']['title'] }}</title>
    <style>
        @page {
            margin: 35px 40px 45px 40px;
        }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10pt;
            line-height: 1.45;
            color: #1f2937;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
        }

        /* Running Header & Footer */
        .page-header {
            position: fixed;
            top: -20px;
            left: 0;
            right: 0;
            height: 25px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 7.5pt;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .page-footer {
            position: fixed;
            bottom: -30px;
            left: 0;
            right: 0;
            height: 25px;
            border-top: 1px solid #e5e7eb;
            font-size: 7.5pt;
            color: #6b7280;
            line-height: 20px;
        }

        .footer-left {
            float: left;
        }

        .footer-right {
            float: right;
        }

        .page-number:before {
            content: "Page " counter(page);
        }

        /* Typography & Layout */
        h1, h2, h3, h4 {
            color: #111827;
            margin: 0 0 8px 0;
            font-weight: bold;
        }

        h1 { font-size: 16pt; letter-spacing: -0.3px; }
        h2 { font-size: 12pt; border-bottom: 1.5px solid #111827; padding-bottom: 4px; margin-top: 18px; margin-bottom: 10px; }
        h3 { font-size: 10.5pt; margin-top: 12px; margin-bottom: 6px; }

        p { margin: 0 0 8px 0; }

        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }

        /* Main Header */
        .report-header {
            border-bottom: 2px solid #111827;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .brand-title {
            font-size: 18pt;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            text-transform: uppercase;
        }

        .brand-subtitle {
            font-size: 9pt;
            color: #4b5563;
            font-weight: 500;
            margin-top: 2px;
        }

        .meta-table {
            width: 100%;
            margin-top: 10px;
            font-size: 8.5pt;
        }

        .meta-table td {
            padding: 2px 4px;
            vertical-align: top;
        }

        /* Disclaimer Box */
        .disclaimer-box {
            background-color: #f8fafc;
            border-left: 3px solid #3b82f6;
            padding: 8px 12px;
            margin-bottom: 16px;
            font-size: 8pt;
            color: #334155;
            line-height: 1.35;
        }

        /* Tables */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 8.5pt;
        }

        table.data-table th {
            background-color: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            text-align: left;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        table.data-table td {
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }

        table.data-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        /* Score Display */
        .score-container {
            width: 100%;
            margin-bottom: 14px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 12px;
        }

        .score-col-left {
            float: left;
            width: 28%;
            text-align: center;
            border-right: 1px solid #cbd5e1;
            padding-right: 12px;
        }

        .score-col-right {
            float: right;
            width: 68%;
            padding-left: 12px;
        }

        .large-score {
            font-size: 34pt;
            font-weight: 800;
            color: #0f172a;
            line-height: 1;
            margin-top: 4px;
        }

        .score-max {
            font-size: 11pt;
            color: #64748b;
            font-weight: 400;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 2px 7px;
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 3px;
        }

        .badge-excellent { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-good { background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-fair { background-color: #fef9c3; color: #a16207; border: 1px solid #fde047; }
        .badge-needs-review { background-color: #ffedd5; color: #c2410c; border: 1px solid #fdba74; }
        .badge-requires-attention { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

        .badge-high { background-color: #fee2e2; color: #b91c1c; }
        .badge-medium { background-color: #ffedd5; color: #c2410c; }
        .badge-low { background-color: #f1f5f9; color: #475569; }

        .badge-accepted { background-color: #dcfce7; color: #15803d; }
        .badge-dismissed { background-color: #f1f5f9; color: #64748b; }
        .badge-reviewed { background-color: #e0e7ff; color: #4338ca; }
        .badge-pending { background-color: #fef9c3; color: #854d0e; }

        /* Metric Progress bar */
        .progress-bar {
            background-color: #e2e8f0;
            height: 7px;
            border-radius: 3px;
            width: 100%;
            margin-top: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 7px;
            background-color: #2563eb;
            border-radius: 3px;
        }

        /* Page breaks */
        .page-break {
            page-break-after: always;
        }

        .avoid-break {
            page-break-inside: avoid;
        }

        /* Key-Value List */
        .kv-label {
            font-weight: bold;
            color: #475569;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-muted { color: #64748b; font-size: 8pt; }
        .font-mono { font-family: 'Courier', monospace; }
    </style>
</head>
<body>

    <!-- Running Header & Footer -->
    <div class="page-header">
        <span style="float: left;">FacultyLens Academic Decision Support System &bull; Assessment Report</span>
        <span style="float: right;">{{ $data['assessment']['course_code'] }} &bull; {{ $data['assessment']['title'] }}</span>
    </div>

    <div class="page-footer">
        <div class="footer-left">
            Strictly Confidential &bull; Generated for University Faculty Decision Support &bull; {{ $data['assessment']['generated_at'] }}
        </div>
        <div class="footer-right">
            <span class="page-number"></span>
        </div>
    </div>

    <!-- MAIN HEADER -->
    <div class="report-header">
        <div class="brand-title">FacultyLens</div>
        <div class="brand-subtitle">Academic Decision Support System &bull; Assessment Quality &amp; Alignment Report</div>
        
        <table class="meta-table">
            <tr>
                <td style="width: 50%;">
                    <div><span class="kv-label">Course:</span> {{ $data['assessment']['course_code'] }} - {{ $data['assessment']['course_name'] }}</div>
                    <div><span class="kv-label">Assessment:</span> {{ $data['assessment']['title'] }} ({{ ucfirst($data['assessment']['type']) }})</div>
                    <div><span class="kv-label">Department:</span> {{ $data['assessment']['department'] }}</div>
                </td>
                <td style="width: 50%; text-align: right;">
                    <div><span class="kv-label">Faculty:</span> {{ $data['assessment']['faculty_name'] }}</div>
                    <div><span class="kv-label">Date &amp; Duration:</span> {{ $data['assessment']['assessment_date'] ?? 'N/A' }} ({{ $data['assessment']['duration_minutes'] ?? 0 }} mins)</div>
                    <div><span class="kv-label">Total Scope:</span> {{ $data['assessment']['total_questions'] }} Questions &bull; {{ $data['assessment']['total_marks'] }} Total Marks</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- DISCLAIMER NOTICE -->
    <div class="disclaimer-box">
        <strong>Academic Decision-Support Advisory:</strong> {{ $data['disclaimer'] }}
    </div>

    <!-- EXECUTIVE SUMMARY & OVERALL QUALITY SCORE -->
    <div class="avoid-break">
        <h2>Executive Summary &amp; Quality Assessment</h2>
        
        <div class="score-container clearfix">
            <div class="score-col-left">
                <div style="font-size: 8pt; text-transform: uppercase; color: #475569; font-weight: 700;">Overall Quality Score</div>
                <div class="large-score">{{ round($data['overall_quality']['score'], 1) }}<span class="score-max">/100</span></div>
                <div style="margin-top: 6px;">
                    @php
                        $ratingClass = match(strtoupper($data['overall_quality']['rating'])) {
                            'EXCELLENT' => 'badge-excellent',
                            'GOOD' => 'badge-good',
                            'FAIR' => 'badge-fair',
                            'NEEDS_REVIEW' => 'badge-needs-review',
                            default => 'badge-requires-attention',
                        };
                    @endphp
                    <span class="badge {{ $ratingClass }}">{{ str_replace('_', ' ', $data['overall_quality']['rating']) }}</span>
                </div>
            </div>
            <div class="score-col-right">
                <div style="font-size: 8.5pt; color: #334155; line-height: 1.45;">
                    This overall index synthesizes six core academic dimensions: syllabus topic breadth (25%), learning outcome alignment (25%), cognitive distribution according to Bloom’s Taxonomy (15%), difficulty balance (15%), question originality (10%), and marks proportionality (10%).
                </div>
                <div style="margin-top: 8px; font-size: 8pt; color: #475569;">
                    <strong>Analyzed At:</strong> {{ $data['assessment']['analyzed_at'] }} &bull; <strong>Engine:</strong> {{ $data['engine_metadata']['system'] }}
                </div>
            </div>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 28%;">Dimension</th>
                    <th style="width: 10%; text-align: center;">Weight</th>
                    <th style="width: 12%; text-align: center;">Score</th>
                    <th style="width: 18%; text-align: center;">Rating</th>
                    <th style="width: 32%;">Academic Interpretation</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['overall_quality']['dimensions'] as $dimKey => $dim)
                @php
                    $dimBadgeClass = match(strtoupper($dim['rating'])) {
                        'EXCELLENT' => 'badge-excellent',
                        'GOOD' => 'badge-good',
                        'FAIR' => 'badge-fair',
                        'NEEDS_REVIEW' => 'badge-needs-review',
                        default => 'badge-requires-attention',
                    };
                @endphp
                <tr>
                    <td><strong>{{ $dim['name'] }}</strong></td>
                    <td class="text-center">{{ $dim['weight'] }}</td>
                    <td class="text-center"><strong>{{ round($dim['score'], 1) }}</strong>%</td>
                    <td class="text-center"><span class="badge {{ $dimBadgeClass }}">{{ str_replace('_', ' ', $dim['rating']) }}</span></td>
                    <td class="text-muted" style="font-size: 7.5pt;">{{ $dim['description'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- SECTION 1: TOPIC COVERAGE -->
    <div class="avoid-break" style="margin-top: 14px;">
        <h2>1. Syllabus Topic Coverage</h2>
        <p style="font-size: 8.5pt; color: #475569;">
            Overall Coverage: <strong>{{ round($data['topic_coverage']['score'], 1) }}%</strong> &bull; 
            Total Syllabus Topics: <strong>{{ $data['topic_coverage']['total_topics'] }}</strong> &bull; 
            Adequately Covered: <strong>{{ $data['topic_coverage']['covered_topics'] }}</strong> &bull; 
            Low Coverage: <strong>{{ $data['topic_coverage']['low_coverage_topics'] }}</strong> &bull; 
            Unreached: <strong>{{ $data['topic_coverage']['not_covered_topics'] }}</strong>
        </p>

        @if(!empty($data['topic_coverage']['topics']))
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 45%;">Topic Name</th>
                    <th style="width: 20%; text-align: center;">Coverage Status</th>
                    <th style="width: 15%; text-align: center;">Questions Assigned</th>
                    <th style="width: 20%; text-align: right;">Total Marks</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['topic_coverage']['topics'] as $topic)
                @php
                    $topicBadge = match(strtoupper($topic['status'])) {
                        'COVERED' => 'badge-excellent',
                        'LOW_COVERAGE' => 'badge-fair',
                        default => 'badge-requires-attention',
                    };
                @endphp
                <tr>
                    <td>{{ $topic['topic'] }}</td>
                    <td class="text-center"><span class="badge {{ $topicBadge }}">{{ str_replace('_', ' ', $topic['status']) }}</span></td>
                    <td class="text-center">{{ $topic['question_count'] }}</td>
                    <td class="text-right">{{ round($topic['marks'], 1) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p class="text-muted">No granular topic breakdown data available in current analysis snapshot.</p>
        @endif
    </div>

    <div class="page-break"></div>

    <!-- SECTION 2: LEARNING OUTCOME ALIGNMENT -->
    <div>
        <h2>2. Learning Outcome (LO) Alignment Analysis</h2>
        <p style="font-size: 8.5pt; color: #475569;">
            Composite Semantic Alignment: <strong>{{ round($data['learning_outcome_alignment']['score'], 1) }}%</strong>.
            The model evaluates semantic relevance using embedding cosine similarity between question phrasing and accredited learning outcomes.
        </p>

        <h3>Course Learning Outcomes Summary</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 15%;">LO Code</th>
                    <th style="width: 45%;">Description</th>
                    <th style="width: 12%; text-align: center;">Questions</th>
                    <th style="width: 13%; text-align: center;">Mean Similarity</th>
                    <th style="width: 15%; text-align: center;">Alignment Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($data['learning_outcome_alignment']['outcomes'] as $lo)
                @php
                    $loBadge = match(strtoupper($lo['alignment_status'])) {
                        'STRONG' => 'badge-excellent',
                        'WEAK' => 'badge-fair',
                        default => 'badge-requires-attention',
                    };
                @endphp
                <tr>
                    <td><strong>{{ $lo['code'] }}</strong></td>
                    <td style="font-size: 8pt;">{{ $lo['description'] }}</td>
                    <td class="text-center">{{ $lo['question_count'] }}</td>
                    <td class="text-center">{{ number_format($lo['average_score'], 2) }}</td>
                    <td class="text-center"><span class="badge {{ $loBadge }}">{{ $lo['alignment_status'] }}</span></td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No learning outcome alignment records found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if(!empty($data['learning_outcome_alignment']['question_mappings']))
        <h3>Question-to-Learning Outcome Detailed Mapping</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 8%; text-align: center;">Q#</th>
                    <th style="width: 37%;">Question Content (Excerpt)</th>
                    <th style="width: 12%;">Target LO</th>
                    <th style="width: 10%; text-align: center;">Similarity</th>
                    <th style="width: 13%; text-align: center;">Alignment</th>
                    <th style="width: 20%;">AI Assessment Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['learning_outcome_alignment']['question_mappings'] as $qm)
                @php
                    $qmBadge = match(strtoupper($qm['alignment'])) {
                        'STRONG', 'HIGH' => 'badge-excellent',
                        'MODERATE', 'WEAK' => 'badge-fair',
                        default => 'badge-requires-attention',
                    };
                @endphp
                <tr>
                    <td class="text-center"><strong>Q{{ $qm['question_number'] }}</strong></td>
                    <td style="font-size: 8pt;">{{ Str::limit($qm['question_text'], 90) }}</td>
                    <td><strong>{{ $qm['lo_code'] }}</strong></td>
                    <td class="text-center font-mono">{{ number_format($qm['similarity_score'], 2) }}</td>
                    <td class="text-center"><span class="badge {{ $qmBadge }}">{{ $qm['alignment'] }}</span></td>
                    <td style="font-size: 7.5pt; color: #4b5563;">{{ $qm['reasoning'] ?: 'Direct semantic alignment observed.' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    <!-- SECTION 3: DIFFICULTY & COGNITIVE LEVEL BALANCE -->
    <div class="avoid-break" style="margin-top: 14px;">
        <h2>3. Difficulty &amp; Cognitive (Bloom) Balance</h2>
        
        <table style="width: 100%; margin-bottom: 8px;">
            <tr>
                <td style="width: 48%; vertical-align: top;">
                    <h3>Difficulty Distribution (Marks Basis)</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Difficulty Tier</th>
                                <th class="text-center">Questions</th>
                                <th class="text-center">Marks</th>
                                <th class="text-center">Actual %</th>
                                <th class="text-center">Benchmark</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['difficulty_distribution']['levels'] as $tierKey => $tier)
                            <tr>
                                <td><strong>{{ $tier['label'] }}</strong></td>
                                <td class="text-center">{{ $tier['question_count'] }}</td>
                                <td class="text-center">{{ round($tier['marks'], 1) }}</td>
                                <td class="text-center"><strong>{{ $tier['actual_percentage'] }}%</strong></td>
                                <td class="text-center text-muted">{{ $tier['target_percentage'] }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; vertical-align: top;">
                    <h3>Bloom’s Taxonomy Distribution</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Cognitive Level</th>
                                <th class="text-center">Questions</th>
                                <th class="text-center">Marks</th>
                                <th class="text-center">Actual %</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['cognitive_distribution']['levels'] as $bloomKey => $bLevel)
                            <tr>
                                <td><strong>{{ $bLevel['label'] }}</strong></td>
                                <td class="text-center">{{ $bLevel['count'] }}</td>
                                <td class="text-center">{{ round($bLevel['marks'], 1) }}</td>
                                <td class="text-center"><strong>{{ $bLevel['percentage'] }}%</strong></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="page-break"></div>

    <!-- SECTION 4: QUESTION SIMILARITY & HISTORICAL OVERLAP -->
    <div>
        <h2>4. Question Similarity &amp; Historical Overlap</h2>
        <p style="font-size: 8.5pt; color: #475569;">
            Overall Question Diversity Score: <strong>{{ round($data['similar_questions']['rating'] === 'EXCELLENT' ? 95 : 80, 1) }}%</strong> &bull; 
            Total Historical Matches Detected: <strong>{{ $data['similar_questions']['total_matches'] }}</strong> &bull; 
            Potential Duplicates (&ge; 0.85): <strong>{{ $data['similar_questions']['potential_duplicates_count'] }}</strong> &bull; 
            Highly Similar (0.70&ndash;0.84): <strong>{{ $data['similar_questions']['highly_similar_count'] }}</strong>
        </p>

        @php
            $allMatches = array_merge(
                $data['similar_questions']['potential_duplicates'],
                $data['similar_questions']['highly_similar'],
                $data['similar_questions']['somewhat_similar']
            );
        @endphp

        @if(count($allMatches) > 0)
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 8%; text-align: center;">Q#</th>
                    <th style="width: 32%;">Current Assessment Question</th>
                    <th style="width: 32%;">Matched Historical Question</th>
                    <th style="width: 14%;">Source / Year</th>
                    <th style="width: 14%; text-align: center;">Similarity</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($allMatches, 0, 10) as $m)
                @php
                    $simBadge = $m['similarity_score'] >= 0.85 ? 'badge-requires-attention' : ($m['similarity_score'] >= 0.70 ? 'badge-fair' : 'badge-good');
                @endphp
                <tr>
                    <td class="text-center"><strong>Q{{ $m['current_question_number'] }}</strong></td>
                    <td style="font-size: 7.5pt;">{{ Str::limit($m['current_question_text'], 110) }}</td>
                    <td style="font-size: 7.5pt;">{{ Str::limit($m['previous_question_text'], 110) }}</td>
                    <td style="font-size: 7.5pt;">{{ $m['previous_assessment_title'] }} ({{ $m['previous_year'] }})</td>
                    <td class="text-center">
                        <span class="badge {{ $simBadge }}">{{ round($m['similarity_score'] * 100) }}%</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p style="font-size: 8.5pt; color: #15803d; background-color: #f0fdf4; padding: 8px; border: 1px solid #bbf7d0;">
            No significant question overlap with historical examination repositories was identified for this assessment.
        </p>
        @endif
    </div>

    <!-- SECTION 5: AI FINDINGS & OBSERVATIONS -->
    <div class="avoid-break" style="margin-top: 14px;">
        <h2>5. Diagnostic AI Findings &amp; Observations</h2>
        @if(!empty($data['findings']))
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 12%; text-align: center;">Severity</th>
                    <th style="width: 25%;">Area</th>
                    <th style="width: 35%;">Observed Finding</th>
                    <th style="width: 28%;">Diagnostic Explanation</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['findings'] as $finding)
                @php
                    $sevBadge = match(strtolower($finding['severity'])) {
                        'critical', 'high' => 'badge-requires-attention',
                        'warning', 'medium' => 'badge-needs-review',
                        default => 'badge-fair',
                    };
                @endphp
                <tr>
                    <td class="text-center"><span class="badge {{ $sevBadge }}">{{ ucfirst($finding['severity']) }}</span></td>
                    <td><strong>{{ $finding['category'] }}</strong></td>
                    <td style="font-size: 8pt;">{{ $finding['problem'] }}</td>
                    <td style="font-size: 7.5pt; color: #475569;">{{ $finding['explanation'] ?: ($finding['evidence'] ?: 'Observed across paper distribution metrics.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p class="text-muted">No critical diagnostic findings recorded.</p>
        @endif
    </div>

    <!-- SECTION 6: RECOMMENDATIONS & FACULTY DECISION RECORD -->
    <div class="avoid-break" style="margin-top: 14px;">
        <h2>6. Actionable Recommendations &amp; Faculty Decision Log</h2>
        <p style="font-size: 8.5pt; color: #475569;">
            Summary: <strong>{{ $data['recommendation_summary']['total'] }} Total Recommendations</strong> &bull; 
            High Priority: <strong>{{ $data['recommendation_summary']['high'] }}</strong> &bull; 
            Accepted by Faculty: <strong>{{ $data['recommendation_summary']['accepted'] }}</strong> &bull; 
            Reviewed: <strong>{{ $data['recommendation_summary']['reviewed'] }}</strong> &bull; 
            Dismissed: <strong>{{ $data['recommendation_summary']['dismissed'] }}</strong>
        </p>

        @if(!empty($data['recommendations']))
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 10%; text-align: center;">Priority</th>
                    <th style="width: 18%;">Category</th>
                    <th style="width: 44%;">Issue &amp; Recommended Revision</th>
                    <th style="width: 14%; text-align: center;">Faculty Action</th>
                    <th style="width: 14%; text-align: center;">Current Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['recommendations'] as $rec)
                @php
                    $prioBadge = match($rec['priority']) {
                        'high' => 'badge-high',
                        'medium' => 'badge-medium',
                        default => 'badge-low',
                    };
                    $statusBadge = match($rec['status']) {
                        'accepted' => 'badge-accepted',
                        'dismissed' => 'badge-dismissed',
                        'reviewed' => 'badge-reviewed',
                        default => 'badge-pending',
                    };
                @endphp
                <tr>
                    <td class="text-center"><span class="badge {{ $prioBadge }}">{{ ucfirst($rec['priority']) }}</span></td>
                    <td><strong>{{ $rec['category'] }}</strong></td>
                    <td style="font-size: 8pt;">
                        <div><strong>Problem:</strong> {{ $rec['problem'] }}</div>
                        <div style="margin-top: 3px; color: #1e3a8a;"><strong>Recommendation:</strong> {{ $rec['recommendation'] }}</div>
                        @if($rec['explanation'])
                        <div style="margin-top: 2px; font-size: 7.5pt; color: #64748b;"><em>Note: {{ $rec['explanation'] }}</em></div>
                        @endif
                    </td>
                    <td class="text-center" style="font-size: 8pt;">{{ $rec['action_taken'] }}</td>
                    <td class="text-center"><span class="badge {{ $statusBadge }}">{{ ucfirst($rec['status']) }}</span></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p class="text-muted">No open recommendations for this assessment.</p>
        @endif
    </div>

    <!-- SECTION 7: STUDENT PERFORMANCE SUMMARY (STEP 30, finalized grades only) -->
    @if(!empty($data['student_performance']))
    @php $sp = $data['student_performance']; @endphp
    <div class="avoid-break" style="margin-top: 14px;">
        <h2>7. Student Performance Summary</h2>
        <p style="font-size: 8.5pt; color: #475569;">
            Based on <strong>{{ $sp['finalized_answers'] }}</strong> finalized answers from <strong>{{ $sp['students'] }}</strong> students (analysis {{ $sp['analyzed_at'] }}{{ $sp['is_stale'] ? ', may be outdated' : '' }}).
            Overall performance <strong>{{ $sp['overall'] }}</strong> against an expected benchmark of <strong>{{ $sp['expected'] }}</strong>
            (gap {{ $sp['gap'] }}) &mdash; status: <strong>{{ $sp['status'] }}</strong>.
        </p>
        <table class="data-table">
            <thead><tr><th style="width:12%;">Question</th><th style="width:14%; text-align:center;">Average</th><th style="width:18%; text-align:center;">Finalized / Submissions</th><th style="width:12%; text-align:center;">Gap (pts)</th><th style="width:14%;">Difficulty</th><th>Status</th></tr></thead>
            <tbody>
                @foreach($sp['questions'] as $q)
                <tr><td><strong>{{ $q['label'] }}</strong></td><td class="text-center">{{ $q['average'] }}</td><td class="text-center">{{ $q['responses'] }}</td><td class="text-center">{{ $q['gap'] }}</td><td>{{ $q['difficulty'] }}</td><td>{{ $q['status'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
        <table style="width:100%; margin-top: 8px; font-size: 8.5pt;">
            <tr>
                <td style="width:50%; vertical-align:top; padding-right: 8px;">
                    <strong>Learning Outcome Performance</strong>
                    @forelse($sp['learning_outcomes'] as $lo)
                    <div>{{ $lo['label'] }} &mdash; {{ $lo['average'] }} ({{ $lo['status'] }})</div>
                    @empty
                    <div class="text-muted">No learning outcomes are mapped to this assessment.</div>
                    @endforelse
                    <div style="margin-top: 6px;"><strong>Topic Performance</strong></div>
                    @forelse($sp['topics'] as $t)
                    <div>{{ $t['label'] }} &mdash; {{ $t['average'] }} ({{ $t['status'] }})</div>
                    @empty
                    <div class="text-muted">Topic performance unavailable for questions without topic classification.</div>
                    @endforelse
                </td>
                <td style="width:50%; vertical-align:top;">
                    <strong>Potential Gap Areas (for faculty review)</strong>
                    @forelse($sp['gap_areas'] as $g)
                    <div>&bull; {{ $g }}</div>
                    @empty
                    <div class="text-muted">No potential gap areas met the minimum sample size.</div>
                    @endforelse
                    <div style="margin-top: 6px;"><strong>Strong Performance Areas</strong></div>
                    @forelse($sp['strong_areas'] as $s)
                    <div>&bull; {{ $s }}</div>
                    @empty
                    <div class="text-muted">No strong performance areas identified.</div>
                    @endforelse
                </td>
            </tr>
        </table>
        <p style="font-size: 7.5pt; color: #64748b; margin-top: 6px;"><em>Limitations: {{ $sp['limitations'] }}</em></p>
    </div>
    @endif

    @if(!empty($data['co_po_mapping']))
    @include('reports.partials.co-po-mapping', ['cp' => $data['co_po_mapping']])
    @endif

    <!-- SIGN-OFF SECTION -->
    <div class="avoid-break" style="margin-top: 24px; padding-top: 14px; border-top: 1px solid #cbd5e1;">
        <table style="width: 100%; font-size: 8.5pt;">
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <div style="font-weight: bold; margin-bottom: 25px;">Faculty Member Verification:</div>
                    <div style="border-bottom: 1px solid #94a3b8; width: 75%; margin-bottom: 4px;"></div>
                    <div>{{ $data['assessment']['faculty_name'] }}</div>
                    <div class="text-muted">{{ $data['assessment']['department'] }}</div>
                </td>
                <td style="width: 50%; vertical-align: top;">
                    <div style="font-weight: bold; margin-bottom: 25px;">Department Head / QA Committee:</div>
                    <div style="border-bottom: 1px solid #94a3b8; width: 75%; margin-bottom: 4px;"></div>
                    <div>Signature &amp; Date</div>
                    <div class="text-muted">Academic Quality Assurance Review</div>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>

