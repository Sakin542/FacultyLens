<?php

namespace App\Services\Explainability;

use App\Models\Course;
use App\Models\User;
use App\Services\CourseAccessService;
use Illuminate\Database\Eloquent\Model;

/**
 * One explainer per AI result type. Explainers derive explanations from *stored* results and existing rules;
 * they never call a generative model and never expose prompts or hidden reasoning.
 */
abstract class AbstractExplainer
{
    abstract public function type(): string;

    /** Load the underlying AI result (or null when it does not exist). */
    abstract public function find(int $id): ?Model;

    /** Course that owns the result — used for authorization. */
    abstract public function course(Model $target): ?Course;

    /** Ability required to view the explanation (config/collaboration.php matrix). */
    public function viewAbility(): string
    {
        return 'view_analysis';
    }

    /** Ability required to review/override (null → review is not supported for this type). */
    public function reviewAbility(): ?string
    {
        return 'view_analysis';
    }

    /** Default authorization: course-level ability via CourseAccessService (STEP 34). Explainers may override. */
    public function canView(User $user, Model $target): bool
    {
        $course = $this->course($target);
        return $course !== null && app(CourseAccessService::class)->can($user, $course, $this->viewAbility());
    }

    public function canReview(User $user, Model $target): bool
    {
        $ability = $this->reviewAbility();
        $course = $this->course($target);
        return $ability !== null && $course !== null && app(CourseAccessService::class)->can($user, $course, $ability);
    }

    abstract public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder;

    /** Snapshot of the AI value stored with a review record. */
    abstract public function aiValue(Model $target): array;

    /** Analysis report id giving version context (null when not applicable). */
    public function analysisReportId(Model $target): ?int
    {
        return null;
    }

    /** Options a faculty member may override to; empty → not overridable. */
    public function overrideOptions(Model $target): array
    {
        return [];
    }

    /**
     * Apply a faculty override to the faculty-controlled field. Must never touch the AI columns.
     *
     * @return array the applied faculty value
     * @throws ExplainabilityException
     */
    public function applyOverride(User $user, Model $target, array $value): array
    {
        throw new ExplainabilityException('This AI result cannot be overridden here.', 422);
    }

    /** Hook after a review action (e.g. delegate to an existing feedback workflow). */
    public function afterReview(User $user, Model $target, string $action, ?string $comment): void
    {
    }

    protected function limitations(string $type): array
    {
        return config("ai_explainability.limitations.{$type}", []);
    }

    protected function fmtScore(?float $score, int $decimals = 2): ?string
    {
        return $score === null ? null : number_format($score, $decimals) . ' / 1.00';
    }

    protected function excerpt(?string $text, int $max = 220): ?string
    {
        if ($text === null) {
            return null;
        }
        $clean = trim(preg_replace('/\s+/', ' ', str_replace(['<<<', '>>>'], ['‹‹‹', '›››'], $text)));
        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max - 1) . '…' : $clean;
    }

    protected function humanize(?string $label): ?string
    {
        return $label === null ? null : ucwords(strtolower(str_replace('_', ' ', $label)));
    }
}
