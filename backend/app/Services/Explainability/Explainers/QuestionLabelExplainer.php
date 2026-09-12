<?php

namespace App\Services\Explainability\Explainers;

use App\Models\AnalysisReport;
use App\Models\Course;
use App\Models\Question;
use App\Models\User;
use App\Services\AiService;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplainabilityException;
use App\Services\Explainability\ExplanationBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Explains the per-question AI labels (question_type / difficulty / bloom / topic) stored on `questions.ai_*`.
 *
 * Cue-word evidence comes from the AI service's deterministic rule tables (POST /api/v1/explain-question),
 * cached per question text + labels. When the AI service is unreachable the explanation still renders from
 * the stored label with evidence_status = "unavailable" — nothing is invented.
 */
class QuestionLabelExplainer extends AbstractExplainer
{
    public const TYPES = ['question_type', 'difficulty', 'bloom', 'topic'];

    protected const AI_COLUMN = ['question_type' => 'ai_question_type', 'difficulty' => 'ai_difficulty_level', 'bloom' => 'ai_cognitive_level', 'topic' => 'ai_topics'];
    protected const FACULTY_COLUMN = ['question_type' => 'question_type', 'difficulty' => 'difficulty_level', 'bloom' => 'cognitive_level'];
    protected const CUE_KEY = ['question_type' => 'question_type', 'difficulty' => 'difficulty', 'bloom' => 'cognitive_level', 'topic' => 'topics'];
    protected const TITLE = ['question_type' => 'Question type', 'difficulty' => 'Difficulty', 'bloom' => 'Bloom level', 'topic' => 'Topic'];

    protected const OPTIONS = [
        'question_type' => ['MCQ', 'SHORT_ANSWER', 'DESCRIPTIVE', 'PROBLEM_SOLVING', 'TRUE_FALSE', 'OTHER'],
        'difficulty' => ['EASY', 'MEDIUM', 'HARD'],
        'bloom' => ['REMEMBER', 'UNDERSTAND', 'APPLY', 'ANALYZE', 'EVALUATE', 'CREATE'],
    ];

    public function __construct(protected string $resultType, protected AiService $ai)
    {
    }

    public function type(): string
    {
        return $this->resultType;
    }

    public function find(int $id): ?Model
    {
        return Question::with('assessment.course')->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->assessment?->course;
    }

    public function reviewAbility(): ?string
    {
        return 'edit_question';
    }

    public function analysisReportId(Model $target): ?int
    {
        return AnalysisReport::currentFor($target->assessment_id)?->id;
    }

    public function aiValue(Model $target): array
    {
        return ['value' => $target->{self::AI_COLUMN[$this->resultType]}, 'analyzed_at' => $target->ai_analyzed_at?->toIso8601String()];
    }

    public function overrideOptions(Model $target): array
    {
        return self::OPTIONS[$this->resultType] ?? [];
    }

    public function applyOverride(User $user, Model $target, array $value): array
    {
        $options = $this->overrideOptions($target);
        if ($options === []) {
            throw new ExplainabilityException('Topics cannot be overridden here; edit the question instead.', 422);
        }
        $label = strtoupper(str_replace([' ', '-'], '_', (string) ($value['label'] ?? '')));
        if (!in_array($label, $options, true)) {
            throw new ExplainabilityException('Override value must be one of: ' . implode(', ', $options) . '.', 422);
        }
        $column = self::FACULTY_COLUMN[$this->resultType];
        $stored = match ($this->resultType) {
            'question_type' => strtolower($label),
            'difficulty' => strtolower($label),
            'bloom' => ucfirst(strtolower($label)),
        };
        $target->{$column} = $stored;          // faculty-controlled column only — ai_* is never modified
        $target->save();

        return ['label' => $label, 'column' => $column];
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        /** @var Question $q */
        $q = $target;
        $type = $this->resultType;
        $aiValue = $q->{self::AI_COLUMN[$type]};
        $isTopic = $type === 'topic';
        $labels = $isTopic ? (is_array($aiValue) ? array_map(fn ($t) => is_array($t) ? ($t['name'] ?? $t['topic'] ?? '') : (string) $t, $aiValue) : []) : null;
        $label = $isTopic ? null : ($aiValue !== null ? strtoupper(str_replace(' ', '_', (string) $aiValue)) : null);

        $cues = $this->cueEvidence($q);
        $cue = $cues[self::CUE_KEY[$type]] ?? null;

        $report = AnalysisReport::currentFor($q->assessment_id);
        $b->analysisContext($report?->id, $report?->analysis_version, $report?->is_current);

        if ($aiValue === null || ($isTopic && $labels === [])) {
            return $b->result(null, null, 'Not analyzed')
                ->summary('FacultyLens has not produced an AI ' . strtolower(self::TITLE[$type]) . ' for this question yet. Run the assessment analysis to generate one.')
                ->evidenceStatus('none', 'No AI result exists for this question.')
                ->method('RULE_BASED', 'FacultyLens rule-based question analysis (not run yet).')
                ->limitations($this->limitations($type))
                ->review($this->reviewMeta($q));
        }

        if ($isTopic) {
            $b->result(implode(', ', $labels), null, implode(', ', $labels), ['labels' => $labels])
                ->method($cue['method'] ?? 'EMBEDDING_BASED', 'Question text compared with course topics using the configured embedding model; falls back to keyword extraction when no course topics exist.',
                    ['Course topics (uploaded material titles and previously detected topics)', 'Question wording'])
                ->model(['embedding_model' => config('ai_explainability.embedding_model'), 'rule_version' => $cues['model']['rule_version'] ?? null])
                ->detail('Context', $cue['context'] ?? 'Course learning material and question wording');
        } else {
            $b->result($label, null, $this->humanize($label))
                ->method('RULE_BASED', 'FacultyLens deterministic rule tables applied to the question wording (directive verbs, cue words, structure).',
                    $this->factorNames($type, $cue))
                ->model(['name' => 'facultylens-question-analyzer', 'version' => '1.0.0', 'rule_version' => $cues['model']['rule_version'] ?? null]);
            $b->detail('Faculty value', $this->humanize($q->{self::FACULTY_COLUMN[$type]}));
        }

        if ($cue) {
            $b->summary($this->rewriteSummary($cue['summary'] ?? '', $type, $label ?? implode(', ', $labels ?? [])));
            foreach ($cue['evidence'] ?? [] as $ev) {
                $b->evidence(['type' => 'question_text', 'label' => 'Question wording: ' . ($ev['label'] ?? 'cue'), 'text' => '"' . $ev['text'] . '"']);
            }
            if (($cue['evidence'] ?? []) === []) {
                $b->evidence(['type' => 'question_text', 'label' => 'Question wording', 'text' => 'No specific cue word matched; the default rule applied to the overall phrasing.']);
            }
            if (isset($cue['factors']) && is_array($cue['factors']) && $type === 'difficulty') {
                foreach ($cue['factors'] as $k => $v) {
                    $b->detail(ucfirst(str_replace('_', ' ', $k)), is_bool($v) ? ($v ? 'yes' : 'no') : $v);
                }
            }
            if (!$isTopic && array_key_exists('consistent', $cue) && $cue['consistent'] === false) {
                $b->evidenceStatus('stale', 'The current question text no longer produces this label (current rules give ' . $this->humanize($cue['detected_label']) . '). Re-run the analysis to refresh it.');
                $b->limitations(['The question text has changed since analysis; this label may be out of date.']);
            }
            $conf = $cue['confidence'] ?? null;
            if ($type === 'question_type' && is_array($conf) && ($conf['available'] ?? false)) {
                $b->confidence((float) $conf['value'], 'Heuristic rule confidence for the matched question-type pattern. It does not guarantee that the result is correct.');
            }
        } else {
            $b->summary($this->fallbackSummary($type, $label ?? implode(', ', $labels ?? [])))
                ->evidenceStatus('unavailable', 'Cue-word evidence could not be loaded because the AI service is unavailable. The stored result is shown unchanged.');
        }

        $b->link('View question', 'question', $q->id, ['assessment_id' => $q->assessment_id]);
        if ($report) {
            $b->link('View assessment analysis', 'analysis', $q->assessment_id, ['analysis_report_id' => $report->id]);
        }

        return $b->limitations($this->limitations($type))->review($this->reviewMeta($q));
    }

    protected function reviewMeta(Question $q): array
    {
        $type = $this->resultType;
        $isTopic = $type === 'topic';
        return [
            'overridable' => !$isTopic,
            'actions' => $isTopic ? ['ACCEPTED', 'REJECTED', 'REVIEWED'] : ['ACCEPTED', 'REJECTED', 'REVIEWED', 'OVERRIDE'],
            'override_options' => $this->overrideOptions($q),
            'faculty_value' => $isTopic ? null : $this->humanize($q->{self::FACULTY_COLUMN[$type]}),
            'faculty_field' => $isTopic ? null : self::FACULTY_COLUMN[$type],
        ];
    }

    protected function cueEvidence(Question $q): ?array
    {
        $labels = [
            'question_type' => $q->ai_question_type,
            'difficulty_level' => $q->ai_difficulty_level,
            'cognitive_level' => $q->ai_cognitive_level,
            'topics' => is_array($q->ai_topics) ? array_values(array_map(fn ($t) => is_array($t) ? (string) ($t['name'] ?? $t['topic'] ?? '') : (string) $t, $q->ai_topics)) : null,
        ];
        $course = $q->assessment?->course;
        $courseTopics = $course ? $course->materials()->pluck('title')->filter()->unique()->values()->all() : [];
        $payload = array_filter(['question_text' => $q->question_text] + $labels + ['course_topics' => $courseTopics ?: null], fn ($v) => $v !== null);
        $key = 'explainability:cues:' . sha1(json_encode($payload));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached ?: null;
        }
        $result = $this->ai->explainQuestion($payload);
        if ($result !== null) {
            Cache::put($key, $result, (int) config('ai_explainability.cue_cache_ttl', 21600));
        }
        return $result;
    }

    protected function factorNames(string $type, ?array $cue): array
    {
        if ($cue && !empty($cue['factor_names'])) {
            return $cue['factor_names'];
        }
        if ($cue && isset($cue['factors']) && array_is_list($cue['factors'])) {
            return $cue['factors'];
        }
        return match ($type) {
            'difficulty' => ['Cue words for reasoning depth', 'Question length', 'Sub-clause structure'],
            'bloom' => ['Leading directive verb', "Bloom-level verb table (Revised Bloom's Taxonomy)"],
            default => ['Directive verbs and answer-format wording', 'Question length'],
        };
    }

    protected function rewriteSummary(string $summary, string $type, string $label): string
    {
        return $summary !== '' ? $summary : $this->fallbackSummary($type, $label);
    }

    protected function fallbackSummary(string $type, string $label): string
    {
        return match ($type) {
            'question_type' => "FacultyLens classified this question as {$label} based on its wording and expected answer format.",
            'difficulty' => "FacultyLens estimated the difficulty as {$label} from the question's cue words, length and structure.",
            'bloom' => "FacultyLens classified this question at the {$label} level of Bloom's taxonomy from its directive wording.",
            default => "FacultyLens detected the topic(s) {$label} by comparing the question with the course topics.",
        };
    }
}
