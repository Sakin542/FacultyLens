<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * STEP 26: One student's answer to one question within a submission.
 * Marks/feedback here are faculty-entered only; AI suggestions live in AiGradingResult (STEP 27).
 */
class StudentAnswer extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'TEXT';
    public const TYPE_FILE = 'FILE';
    public const TYPE_IMAGE = 'IMAGE';
    public const TYPE_MCQ = 'MCQ';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_FILE, self::TYPE_IMAGE, self::TYPE_MCQ];

    public const STATUS_NOT_REVIEWED = 'NOT_REVIEWED';
    public const STATUS_UNDER_REVIEW = 'UNDER_REVIEW';
    public const STATUS_REVIEWED = 'REVIEWED';

    public const STATUSES = [self::STATUS_NOT_REVIEWED, self::STATUS_UNDER_REVIEW, self::STATUS_REVIEWED];

    protected $fillable = [
        'student_submission_id',
        'question_id',
        'assessment_version_question_id',
        'answer_type',
        'answer_text',
        'original_answer_text',
        'is_faculty_edited',
        'answer_file_path',
        'answer_file_name',
        'answer_file_type',
        'answer_file_size',
        'awarded_marks',
        'faculty_feedback',
        'answer_status',
    ];

    /** Never expose the storage path to clients. */
    protected $hidden = ['answer_file_path'];

    protected function casts(): array
    {
        return [
            'is_faculty_edited' => 'boolean',
            'answer_file_size' => 'integer',
            'awarded_marks' => 'decimal:2',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(StudentSubmission::class, 'student_submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** STEP 38: the question snapshot the student actually answered (immune to later question edits). */
    public function versionQuestion(): BelongsTo
    {
        return $this->belongsTo(AssessmentVersionQuestion::class, 'assessment_version_question_id');
    }

    public function aiGradingResults(): HasMany
    {
        return $this->hasMany(AiGradingResult::class)->orderByDesc('id');
    }

    /** The most recent AI grading run (superseded runs are kept for audit). */
    public function currentAiGrading(): HasOne
    {
        return $this->hasOne(AiGradingResult::class)->where('is_current', true);
    }

    public function rubricAlignments(): HasMany
    {
        return $this->hasMany(AnswerRubricAlignment::class)->orderByDesc('id');
    }

    /** STEP 28: the most recent Answer <-> Rubric alignment run. */
    public function currentRubricAlignment(): HasOne
    {
        return $this->hasOne(AnswerRubricAlignment::class)->where('is_current', true);
    }

    public function hasFile(): bool
    {
        return !empty($this->answer_file_path);
    }

    /**
     * Fingerprint of the evaluated content so AI results can be flagged stale when the answer changes.
     */
    public function contentFingerprint(): string
    {
        return hash('sha256', implode('|', [
            (string) $this->answer_text,
            (string) $this->answer_file_path,
            (string) $this->answer_file_size,
        ]));
    }
}
