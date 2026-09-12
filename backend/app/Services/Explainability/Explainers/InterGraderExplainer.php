<?php

namespace App\Services\Explainability\Explainers;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\User;
use App\Services\Analytics\AiAnalyticsService;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplanationBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Inter-grader consistency (STEP 29 "FacultyLens Agreement Indicator"). Reports the descriptive statistics that
 * define the indicator and states honestly when the data is not available in this deployment (STEP 45 §27).
 */
class InterGraderExplainer extends AbstractExplainer
{
    public function __construct(protected AiAnalyticsService $analytics)
    {
    }

    public function type(): string
    {
        return 'inter_grader';
    }

    public function find(int $id): ?Model
    {
        return Assessment::with('course')->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->course;
    }

    public function viewAbility(): string
    {
        return 'view_student_data';
    }

    public function reviewAbility(): ?string
    {
        return null;
    }

    public function aiValue(Model $target): array
    {
        return ['assessment_id' => $target->id];
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        $ig = $this->analytics->interGrader();
        $available = (bool) ($ig['available'] ?? false);
        $name = $ig['indicator_name'] ?? 'FacultyLens Agreement Indicator';

        $b->result($available ? 'AVAILABLE' : 'NOT_AVAILABLE', null, $available ? 'Available' : 'Not available in this deployment')
            ->summary($available
                ? "The {$name} summarises how closely independent faculty graders agreed on each question."
                : ($ig['message'] ?? 'Inter-grader consistency data is not available in this deployment; FacultyLens does not fabricate agreement values.'))
            ->detail('Indicator', $name)
            ->detail('Statistics reported', ['Grader Count', 'Mean', 'Median', 'Minimum', 'Maximum', 'Range', 'Normalized Difference', 'Agreement Indicator'])
            ->detail('Normalized difference', '(Maximum − Minimum) ÷ Maximum marks for the question')
            ->detail('AI marks', 'AI-suggested marks are excluded from faculty agreement calculations by default.')
            ->method('RULE_BASED', 'Descriptive statistics over faculty-entered marks; no model is involved.', ['Faculty marks per grader', 'Range and normalized difference'])
            ->confidence(null, 'Descriptive indicator — no confidence applies.')
            ->limitations($this->limitations('inter_grader'))
            ->link('Open assessment', 'assessment', $target->id)
            ->review(['overridable' => false, 'actions' => []]);
        if (!$available) {
            $b->evidenceStatus('none', 'No inter-grader data exists for this assessment.');
        }
        return $b;
    }
}
