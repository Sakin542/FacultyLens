<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** STEP 31: One validation finding with a STEP 14-style recommendation. Severity never implies accreditation failure. */
class CoPoMappingFinding extends Model
{
    use HasFactory;

    public const TYPES = [
        'UNMAPPED_QUESTION', 'UNASSESSED_CO', 'LOW_CO_COVERAGE', 'CO_CONCENTRATION', 'UNMAPPED_PO',
        'LOW_PO_EVIDENCE', 'MAPPING_DENSITY', 'CO_COGNITIVE_MISMATCH', 'CO_PERFORMANCE_GAP', 'MAPPING_REVIEW',
    ];
    public const SEVERITIES = ['INFO', 'LOW', 'MEDIUM', 'HIGH'];
    public const SEVERITY_ORDER = ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2, 'INFO' => 3];

    protected $fillable = [
        'analysis_run_id', 'type', 'severity', 'title', 'description', 'recommendation', 'category', 'priority',
        'course_outcome_id', 'program_outcome_id', 'question_id', 'evidence',
    ];

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CoPoMappingAnalysisRun::class, 'analysis_run_id');
    }

    public function courseOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class, 'course_outcome_id');
    }

    public function programOutcome(): BelongsTo
    {
        return $this->belongsTo(ProgramOutcome::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
