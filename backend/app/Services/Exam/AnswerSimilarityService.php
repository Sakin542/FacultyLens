<?php

namespace App\Services\Exam;

use App\Models\AnswerSimilarityRun;
use App\Models\Assessment;
use App\Models\Question;
use App\Models\StudentAnswer;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds pairs of student answers to the same question that look copied.
 *
 * Lexical score: overlap of word 3-grams, after removing every 3-gram that also appears in the question text
 * (students quoting the question is not copying). Average of Jaccard and the overlap coefficient, so copying
 * part of another answer still scores.
 *
 * Semantic score (when the AI service is up): MiniLM cosine, rescaled against the class baseline for that
 * question. All answers to one question are semantically alike, so only unusually close pairs gain anything.
 *
 * The result is a review list for faculty, never an automatic penalty.
 */
class AnswerSimilarityService
{
    public const DEFAULT_THRESHOLD = 0.60;
    public const MIN_WORDS = 8;
    public const MAX_ANSWERS_PER_QUESTION = 300;

    /** Model that produced the most recent embeddings (bge-m3, or MiniLM when the AI service fell back). */
    protected ?string $embeddingModel = null;

    public function __construct(protected AiService $ai) {}

    public function run(Assessment $assessment, User $by, float $threshold = self::DEFAULT_THRESHOLD, bool $useSemantic = true): AnswerSimilarityRun
    {
        $questions = Question::query()->where('assessment_id', $assessment->id)->get(['id', 'question_text', 'question_number']);
        $answersCompared = 0;
        $pairsCompared = 0;
        $matches = [];
        $semanticUsed = false;
        $semanticFailed = false;
        $perQuestion = [];

        foreach ($questions as $question) {
            $answers = StudentAnswer::query()
                ->where('question_id', $question->id)
                ->whereHas('submission', fn ($q) => $q->where('assessment_id', $assessment->id))
                ->whereNotNull('answer_text')
                ->limit(self::MAX_ANSWERS_PER_QUESTION)
                ->get(['id', 'student_submission_id', 'answer_text'])
                ->filter(fn ($a) => count(array_filter(explode(' ', $this->normalize($a->answer_text)))) >= self::MIN_WORDS)
                ->values();
            if ($answers->count() < 2) {
                continue;
            }

            $answersCompared += $answers->count();
            $texts = $answers->pluck('answer_text')->all();
            $vectors = null;
            if ($useSemantic && !$semanticFailed) {
                $vectors = $this->embed($texts);
                $semanticUsed = $semanticUsed || $vectors !== null;
                $semanticFailed = $vectors === null;
            }

            $flagged = 0;
            foreach ($this->scorePairs($texts, (string) $question->question_text, $vectors) as $pair) {
                [$i, $j] = [$pair['a'], $pair['b']];
                $pairsCompared++;
                if ($answers[$i]->student_submission_id === $answers[$j]->student_submission_id) {
                    continue;
                }
                if ($pair['combined'] >= $threshold) {
                    $flagged++;
                    $matches[] = [
                        'question_id' => $question->id,
                        'answer_a_id' => $answers[$i]->id,
                        'answer_b_id' => $answers[$j]->id,
                        'lexical_score' => round($pair['lexical'], 4),
                        'semantic_score' => $pair['semantic'] === null ? null : round($pair['semantic'], 4),
                        'combined_score' => round(min(1.0, $pair['combined']), 4),
                        'shared_excerpt' => $this->sharedExcerpt($texts[$i], $texts[$j]),
                    ];
                }
            }
            $perQuestion[] = ['question_id' => $question->id, 'question_number' => $question->question_number, 'answers' => count($texts), 'flagged_pairs' => $flagged];
        }

        return DB::transaction(function () use ($assessment, $by, $threshold, $answersCompared, $pairsCompared, $matches, $semanticUsed, $semanticFailed, $perQuestion) {
            $run = AnswerSimilarityRun::query()->create([
                'assessment_id' => $assessment->id,
                'run_by' => $by->id,
                'status' => 'completed',
                'method' => $semanticUsed ? 'lexical+semantic' : 'lexical',
                'threshold' => $threshold,
                'answers_compared' => $answersCompared,
                'pairs_compared' => $pairsCompared,
                'pairs_flagged' => count($matches),
                'summary' => [
                    'per_question' => $perQuestion,
                    'semantic_unavailable' => $semanticFailed,
                    'embedding_model' => $semanticUsed ? $this->embeddingModel : null,
                    'min_words' => self::MIN_WORDS,
                ],
            ]);
            foreach ($matches as $m) {
                $run->matches()->create($m);
            }

            return $run;
        });
    }

    /**
     * Ad-hoc check for answers that are not stored in FacultyLens (pasted text, OCR of handwritten scripts).
     * Same scoring as the assessment check; nothing is saved.
     *
     * @param  array<int, array{label: string, text: string}>  $answers
     */
    public function compareTexts(array $answers, string $questionText = '', float $threshold = self::DEFAULT_THRESHOLD, bool $useSemantic = true): array
    {
        $answers = array_values($answers);
        $short = [];
        $usable = [];
        foreach ($answers as $idx => $a) {
            if (count(array_filter(explode(' ', $this->normalize($a['text'])))) >= self::MIN_WORDS) {
                $usable[] = $idx;
            } else {
                $short[] = $a['label'];
            }
        }
        $texts = array_map(fn ($idx) => $answers[$idx]['text'], $usable);
        $vectors = $useSemantic && count($texts) >= 2 ? $this->embed($texts) : null;

        $pairs = [];
        foreach ($this->scorePairs($texts, $questionText, $vectors) as $p) {
            $pairs[] = [
                'a' => $answers[$usable[$p['a']]]['label'],
                'b' => $answers[$usable[$p['b']]]['label'],
                'lexical_score' => round($p['lexical'], 4),
                'semantic_score' => $p['semantic'] === null ? null : round($p['semantic'], 4),
                'combined_score' => round(min(1.0, $p['combined']), 4),
                'flagged' => $p['combined'] >= $threshold,
                'shared_excerpt' => $p['combined'] >= 0.25 ? $this->sharedExcerpt($texts[$p['a']], $texts[$p['b']]) : null,
            ];
        }
        usort($pairs, fn ($x, $y) => $y['combined_score'] <=> $x['combined_score']);

        return [
            'method' => $vectors !== null ? 'lexical+semantic' : 'lexical',
            'semantic_unavailable' => $useSemantic && count($texts) >= 2 && $vectors === null,
            'embedding_model' => $vectors !== null ? $this->embeddingModel : null,
            'threshold' => $threshold,
            'answers_compared' => count($texts),
            'too_short' => $short,
            'min_words' => self::MIN_WORDS,
            'pairs_compared' => count($pairs),
            'pairs_flagged' => count(array_filter($pairs, fn ($p) => $p['flagged'])),
            'pairs' => array_slice($pairs, 0, 50),
        ];
    }

    /**
     * Score every pair of answers to one question.
     *
     * @param  array<int, string>  $texts
     * @param  array<int, array<int, float>>|null  $vectors  answer embeddings, same order as $texts
     * @return array<int, array{a: int, b: int, lexical: float, semantic: ?float, combined: float}>
     */
    public function scorePairs(array $texts, string $questionText, ?array $vectors): array
    {
        $texts = array_values($texts);
        $count = count($texts);
        $questionShingles = $this->shingles($this->normalize($questionText));
        $shingles = array_map(fn ($t) => array_diff_key($this->shingles($this->normalize((string) $t)), $questionShingles), $texts);

        $semanticPairs = [];
        $baseline = 0.0;
        if ($vectors) {
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $semanticPairs[$i][$j] = $this->cosine($vectors[$i], $vectors[$j]);
                }
            }
            $all = [];
            array_walk_recursive($semanticPairs, function ($v) use (&$all) { $all[] = $v; });
            sort($all);
            $baseline = $all ? $all[(int) floor(count($all) / 2)] : 0.0;
        }

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $lexical = $this->lexical($shingles[$i], $shingles[$j]);
                $semantic = $vectors ? $semanticPairs[$i][$j] : null;
                $combined = $lexical;
                if ($semantic !== null) {
                    $adjusted = $baseline < 1 ? max(0.0, ($semantic - $baseline) / (1 - $baseline)) : 0.0;
                    $combined = max($lexical, 0.6 * $lexical + 0.4 * $adjusted);
                }
                $out[] = ['a' => $i, 'b' => $j, 'lexical' => $lexical, 'semantic' => $semantic, 'combined' => $combined];
            }
        }

        return $out;
    }

    /**
     * Multilingual answer embeddings (bge-m3) from the AI toolkit, else the shared MiniLM embeddings, else null (the
     * check then runs on wording alone). Scores are relative to the median pair, so either model works.
     */
    protected function embed(array $texts): ?array
    {
        $texts = array_values($texts);
        foreach (['answerEmbeddings', 'generateEmbeddings'] as $method) {
            try {
                $result = $this->ai->{$method}($texts);
                $this->embeddingModel = $result['model'] ?: null;

                return $result['embeddings'];
            } catch (Throwable $e) {
                Log::info("Plagiarism check: {$method} unavailable (".class_basename($e).')');
            }
        }
        $this->embeddingModel = null;

        return null;
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower(strip_tags($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return array<string, true> word 3-grams */
    public function shingles(string $normalized): array
    {
        $words = $normalized === '' ? [] : explode(' ', $normalized);
        $out = [];
        for ($i = 0; $i + 2 < count($words); $i++) {
            $out[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
        }

        return $out;
    }

    public function lexical(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $inter;
        $jaccard = $union > 0 ? $inter / $union : 0.0;
        $overlap = $inter / min(count($a), count($b));

        return ($jaccard + $overlap) / 2;
    }

    /** Longest run of words both answers share (shown to the reviewer as evidence). */
    public function sharedExcerpt(string $a, string $b): ?string
    {
        $wa = array_slice(explode(' ', $this->normalize($a)), 0, 400);
        $wb = array_slice(explode(' ', $this->normalize($b)), 0, 400);
        $best = 0;
        $end = 0;
        $prev = array_fill(0, count($wb) + 1, 0);
        for ($i = 1; $i <= count($wa); $i++) {
            $cur = array_fill(0, count($wb) + 1, 0);
            for ($j = 1; $j <= count($wb); $j++) {
                if ($wa[$i - 1] === $wb[$j - 1]) {
                    $cur[$j] = $prev[$j - 1] + 1;
                    if ($cur[$j] > $best) {
                        $best = $cur[$j];
                        $end = $i;
                    }
                }
            }
            $prev = $cur;
        }

        return $best >= 4 ? mb_strimwidth(implode(' ', array_slice($wa, $end - $best, $best)), 0, 600, '…') : null;
    }

    protected function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i] ?? 0.0;
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
