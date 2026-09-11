<?php

namespace App\Services\Reports;

use App\Models\Assessment;
use App\Models\AssessmentVersion;
use App\Models\Course;
use App\Models\User;

/**
 * Resolved, authorized inputs for one report build. Course/assessment ids are the ONLY data
 * boundary builders may query within — they are computed server-side from the user's access.
 */
final class ReportContext
{
    /**
     * @param int[] $courseIds
     * @param int[] $assessmentIds
     * @param int[] $studentDataAssessmentIds assessments where aggregated student data may be included
     */
    public function __construct(
        public readonly User $user,
        public readonly string $type,
        public readonly array $definition,
        public readonly string $scope,
        public readonly array $filters,
        public readonly array $courseIds,
        public readonly array $assessmentIds,
        public readonly array $studentDataAssessmentIds,
        public readonly ?Course $course = null,
        public readonly ?Assessment $assessment = null,
        public readonly ?AssessmentVersion $version = null,
        public readonly ?string $department = null,
        public readonly ?int $programId = null,
    ) {}

    public function label(): string
    {
        return (string) ($this->definition['label'] ?? $this->type);
    }

    public function usesStudentData(): bool
    {
        return (bool) ($this->definition['student_data'] ?? false);
    }

    public function scopeDescription(): string
    {
        return match ($this->scope) {
            'ASSESSMENT_VERSION' => sprintf('%s · %s · %s', $this->course?->course_code, $this->assessment?->title, $this->version?->version_label),
            'ASSESSMENT' => sprintf('%s · %s', $this->course?->course_code, $this->assessment?->title),
            'COURSE' => sprintf('%s — %s (%s %s)', $this->course?->course_code, $this->course?->course_name, $this->course?->semester, $this->course?->academic_year),
            'DEPARTMENT' => 'Department: ' . ($this->department ?? 'All'),
            'INSTITUTION' => 'Institution-wide (aggregated)',
            default => 'Faculty: ' . $this->user->name,
        };
    }
}
