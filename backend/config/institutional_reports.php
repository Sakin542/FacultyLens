<?php

use App\Services\Reports\AcademicAnalyticsReportBuilder;
use App\Services\Reports\AiEvaluationReportBuilder;
use App\Services\Reports\AssessmentQualityReportBuilder;
use App\Services\Reports\AssessmentReportBuilder;
use App\Services\Reports\BlueprintReportBuilder;
use App\Services\Reports\GradingReportBuilder;
use App\Services\Reports\InstitutionalSummaryReportBuilder;
use App\Services\Reports\InterGraderReportBuilder;
use App\Services\Reports\LearningGapReportBuilder;
use App\Services\Reports\OutcomeReportBuilder;
use App\Services\Reports\PerformanceReportBuilder;
use App\Services\Reports\QuestionAnalysisReportBuilder;
use App\Services\Reports\RubricReportBuilder;
use App\Services\Reports\VersionHistoryReportBuilder;

/*
 | STEP 39 — Institutional Export & Reporting.
 | Single registry of report types, the scopes each may run at, the filters that apply and the
 | roles that may use institution-wide scopes. A report is an evidence artifact; nothing here
 | changes grades, assessments, mappings or approvals.
 */
return [
    // Private disk + directory for generated files (never publicly served)
    'disk' => env('REPORTS_DISK', 'local'),
    'directory' => 'institutional-reports',

    // Generated files are removed after this many days (audit rows are kept)
    'expiration_days' => (int) env('REPORTS_EXPIRATION_DAYS', 7),

    // Reports whose estimated record count exceeds this, or that run at DEPARTMENT/INSTITUTION scope, are queued
    'async_threshold_records' => (int) env('REPORTS_ASYNC_THRESHOLD', 2000),

    // Rows per table rendered into a PDF; larger tables are truncated with a note recommending CSV/XLSX
    'pdf_row_limit' => (int) env('REPORTS_PDF_ROW_LIMIT', 300),

    // Rows returned per table by the preview endpoint
    'preview_row_limit' => 10,

    'formats' => ['PDF', 'CSV', 'XLSX'],

    'statuses' => ['PENDING', 'PROCESSING', 'COMPLETED', 'FAILED', 'CANCELLED'],

    'scopes' => ['FACULTY', 'COURSE', 'ASSESSMENT', 'ASSESSMENT_VERSION', 'DEPARTMENT', 'INSTITUTION'],

    // The project has FACULTY and ADMIN roles (User::role). Broader scopes are limited to these roles;
    // the server decides — scope claims from the client are never trusted.
    'department_roles' => ['ADMIN'],
    'institution_roles' => ['ADMIN'],

    // Filters that may be sent, and which are course-level vs assessment-level
    'filter_keys' => ['course_id', 'assessment_id', 'assessment_version_id', 'semester', 'academic_year', 'assessment_type', 'department', 'program_id', 'start_date', 'end_date', 'status'],

    'scope_filters' => [
        'FACULTY' => ['semester', 'academic_year', 'assessment_type', 'start_date', 'end_date', 'status'],
        'COURSE' => ['course_id', 'assessment_type', 'start_date', 'end_date', 'status'],
        'ASSESSMENT' => ['course_id', 'assessment_id'],
        'ASSESSMENT_VERSION' => ['course_id', 'assessment_id', 'assessment_version_id'],
        'DEPARTMENT' => ['department', 'semester', 'academic_year', 'assessment_type', 'start_date', 'end_date', 'status'],
        'INSTITUTION' => ['semester', 'academic_year', 'assessment_type', 'program_id', 'start_date', 'end_date', 'status'],
    ],

    /*
     | key => label, description, allowed scopes, whether the report is built from student data
     | (requires the collaboration ability view_student_data at course level) and the builder class.
     */
    'types' => [
        'ASSESSMENT' => ['label' => 'Assessment Report', 'description' => 'Structure, question profile, coverage, similarity findings and recommendations for one assessment or version.',
            'scopes' => ['ASSESSMENT', 'ASSESSMENT_VERSION'], 'student_data' => false, 'builder' => AssessmentReportBuilder::class],
        'ASSESSMENT_QUALITY' => ['label' => 'Assessment Quality Report', 'description' => 'STEP 13 quality dimensions and ratings; aggregated counts at course, faculty, department or institution scope.',
            'scopes' => ['ASSESSMENT', 'ASSESSMENT_VERSION', 'COURSE', 'FACULTY', 'DEPARTMENT', 'INSTITUTION'], 'student_data' => false, 'builder' => AssessmentQualityReportBuilder::class],
        'ASSESSMENT_BLUEPRINT' => ['label' => 'Assessment Blueprint Report', 'description' => 'Blueprint targets, validation status and target-vs-actual comparison with the question set.',
            'scopes' => ['ASSESSMENT'], 'student_data' => false, 'builder' => BlueprintReportBuilder::class],
        'ASSESSMENT_VERSION_HISTORY' => ['label' => 'Assessment Version History', 'description' => 'Every version of an assessment with status, approvals, change summaries and version-to-version differences.',
            'scopes' => ['ASSESSMENT'], 'student_data' => false, 'builder' => VersionHistoryReportBuilder::class],
        'QUESTION_ANALYSIS' => ['label' => 'Question Analysis Report', 'description' => 'Question-level marks, difficulty, cognitive level, outcome mapping and analysis findings.',
            'scopes' => ['ASSESSMENT', 'ASSESSMENT_VERSION'], 'student_data' => false, 'builder' => QuestionAnalysisReportBuilder::class],
        'CO_COVERAGE' => ['label' => 'CO/LO Coverage Report', 'description' => 'Learning-outcome coverage evidence: strong, weak and non-aligned questions per outcome.',
            'scopes' => ['COURSE', 'ASSESSMENT', 'FACULTY', 'DEPARTMENT', 'INSTITUTION'], 'student_data' => false, 'builder' => OutcomeReportBuilder::class],
        'PO_COVERAGE' => ['label' => 'PO Coverage Report', 'description' => 'Program-outcome evidence from the current CO/PO analysis, where a program is configured.',
            'scopes' => ['COURSE', 'FACULTY', 'DEPARTMENT', 'INSTITUTION'], 'student_data' => false, 'builder' => OutcomeReportBuilder::class],
        'STUDENT_PERFORMANCE' => ['label' => 'Student Performance Report', 'description' => 'Aggregated performance from finalized faculty grades: averages, distribution, question, topic and outcome performance.',
            'scopes' => ['ASSESSMENT', 'COURSE', 'FACULTY', 'DEPARTMENT', 'INSTITUTION'], 'student_data' => true, 'builder' => PerformanceReportBuilder::class],
        'LEARNING_GAPS' => ['label' => 'Learning Gap Report', 'description' => 'STEP 30 gap classification per outcome and topic against the configured benchmark.',
            'scopes' => ['ASSESSMENT', 'COURSE', 'FACULTY', 'DEPARTMENT', 'INSTITUTION'], 'student_data' => true, 'builder' => LearningGapReportBuilder::class],
        'RUBRIC' => ['label' => 'Rubric Report', 'description' => 'Rubrics, criteria, maximum marks and scoring guidance per question.',
            'scopes' => ['ASSESSMENT', 'COURSE'], 'student_data' => false, 'builder' => RubricReportBuilder::class],
        'GRADING' => ['label' => 'Grading Report', 'description' => 'Aggregated finalized grades per question with the share of AI-assisted versus faculty-only grading.',
            'scopes' => ['ASSESSMENT', 'COURSE'], 'student_data' => true, 'builder' => GradingReportBuilder::class],
        'INTER_GRADER' => ['label' => 'Inter-Grader Consistency Report', 'description' => 'FacultyLens Agreement Indicator across graders, where multi-grader data exists.',
            'scopes' => ['ASSESSMENT', 'COURSE'], 'student_data' => true, 'builder' => InterGraderReportBuilder::class],
        'AI_EVALUATION' => ['label' => 'AI Evaluation Report', 'description' => 'Model, prompt and dataset versions with evaluated metrics per task and run.',
            'scopes' => ['FACULTY', 'INSTITUTION'], 'student_data' => false, 'builder' => AiEvaluationReportBuilder::class],
        'ACADEMIC_ANALYTICS' => ['label' => 'Academic Analytics Report', 'description' => 'Export of the STEP 36 analytics overview: KPIs, quality, coverage, performance, gaps, rubrics, grading and AI evaluation.',
            'scopes' => ['FACULTY', 'COURSE', 'DEPARTMENT', 'INSTITUTION'], 'student_data' => false, 'builder' => AcademicAnalyticsReportBuilder::class],
        'INSTITUTIONAL_SUMMARY' => ['label' => 'Institutional Summary Report', 'description' => 'Institution-level aggregates across courses, assessments, quality, coverage, performance and AI evaluation status.',
            'scopes' => ['INSTITUTION'], 'student_data' => false, 'builder' => InstitutionalSummaryReportBuilder::class],
    ],

    // Wording is deliberately evidence-based; no accreditation compliance claims are generated.
    'footer' => "FacultyLens — AI-Powered Academic Decision Support\nThis report is generated from FacultyLens system data. AI-generated findings are assistive and should be reviewed by authorized academic personnel.",
    'sensitive_footer' => 'Student performance figures are aggregated from finalized faculty grades. No student names, identifiers or individual answers are included. Handle in line with institutional privacy policy.',
];
