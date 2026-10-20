<?php

namespace App\Services\Admin;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin-editable runtime toggles. Definitions (and defaults) live here; only overrides are stored in system_settings.
 * Toggles are enforced server-side by the `feature:<key>` middleware, not just hidden in the UI.
 */
class FeatureFlagService
{
    protected const CACHE_KEY = 'system_settings:features';

    /** @var array<string, array{label: string, description: string, type: string, default: mixed, public: bool, options?: array<string, string>}> */
    public const DEFINITIONS = [
        'registration' => [
            'label' => 'Faculty self-registration',
            'description' => 'New faculty can create accounts from the sign-up page.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'google_sign_in' => [
            'label' => 'Google sign-in',
            'description' => 'Show "Sign in with Google" (requires GOOGLE_CLIENT_ID).',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_analysis' => [
            'label' => 'AI assessment analysis',
            'description' => 'Quality, alignment, similarity and Bloom analysis of assessments.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'academic_chat' => [
            'label' => 'Document chat',
            'description' => 'Chat with course documents (retrieval + answer generation).',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'question_generation' => [
            'label' => 'Question generator',
            'description' => 'AI question drafting and question variants.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_grading' => [
            'label' => 'AI grading assistance',
            'description' => 'Rubric generation and AI-suggested grades for student answers.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_provider' => [
            'label' => 'AI generation provider',
            'description' => 'Local runs open Hugging Face models on your own server (private). Hosted calls the API provider configured in the AI service (LLM_HOSTED_*); academic text is then sent to that provider. If the hosted call fails, the local model is used.',
            'type' => 'select', 'default' => 'local', 'public' => false,
            'options' => ['local' => 'Local Hugging Face models (private)', 'hosted' => 'Hosted API provider'],
        ],
        'ai_translation' => [
            'label' => 'Bangla ↔ English translation',
            'description' => 'AI Toolkit: translate questions, feedback and materials (NLLB-200 locally, or the hosted model).',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_ocr' => [
            'label' => 'Handwriting OCR',
            'description' => 'AI Toolkit: turn photos or PDFs of handwritten scripts into draft text (TrOCR, local).',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_transcription' => [
            'label' => 'Lecture transcription',
            'description' => 'AI Toolkit: transcribe lecture recordings (Whisper, local). Uses significant CPU while running.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_study_pack' => [
            'label' => 'Notes & quiz from lectures',
            'description' => 'AI Toolkit: summary, key points and a grounded draft quiz from a transcript or course material.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_speech' => [
            'label' => 'Read aloud (text to speech)',
            'description' => 'AI Toolkit: listen to English or Bangla text (Meta MMS-TTS, local).',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_writing' => [
            'label' => 'Question checker, simplify & Bloom rewriter',
            'description' => 'AI Toolkit: question quality checks with rewrite suggestions, text simplification and Bloom-level rewrites.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_feedback' => [
            'label' => 'Feedback drafter',
            'description' => 'Rubric-based feedback drafts for a student answer (AI Toolkit and the grading form). Never gives marks.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_answer_compare' => [
            'label' => 'Compare answers (similarity)',
            'description' => 'AI Toolkit: similarity check for pasted or OCR\'d answers that are not stored in FacultyLens.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_paper_review' => [
            'label' => 'Exam paper review',
            'description' => 'AI Toolkit: Bloom balance, topic / outcome coverage and duplicate questions for a whole paper.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'ai_class_summary' => [
            'label' => 'Class performance summary',
            'description' => 'Plain-language note on what the class struggled with, from finalized marks and rubric evidence.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'announcement' => [
            'label' => 'Announcement banner',
            'description' => 'Message shown to every signed-in user (leave empty to hide).',
            'type' => 'string', 'default' => '', 'public' => true,
        ],
    ];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 60, function () {
            try {
                if (!Schema::hasTable('system_settings')) {
                    return [];
                }

                return SystemSetting::query()->pluck('value', 'key')->all();
            } catch (Throwable) {
                return [];
            }
        });

        $values = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $value = array_key_exists($key, $stored) ? $this->cast($def['type'], $stored[$key]) : $def['default'];
            // A stored select value that is no longer an option falls back to the default
            if ($def['type'] === 'select' && !array_key_exists($value, $def['options'] ?? [])) {
                $value = $def['default'];
            }
            $values[$key] = $value;
        }

        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? (self::DEFINITIONS[$key]['default'] ?? null);
    }

    public function enabled(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** Values safe for anonymous visitors (login/register pages). */
    public function publicValues(): array
    {
        return array_intersect_key($this->all(), array_filter(self::DEFINITIONS, fn ($d) => $d['public']));
    }

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        $values = $this->all();

        return collect(self::DEFINITIONS)->map(fn ($def, $key) => [
            'key' => $key,
            'label' => $def['label'],
            'description' => $def['description'],
            'type' => $def['type'],
            'value' => $values[$key],
            'default' => $def['default'],
            'options' => isset($def['options']) ? collect($def['options'])->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all() : null,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $changes  unknown keys are ignored
     * @return array<string, mixed> the keys that actually changed (new values)
     */
    public function update(array $changes, ?User $by = null): array
    {
        $current = $this->all();
        $changed = [];
        foreach ($changes as $key => $value) {
            if (!isset(self::DEFINITIONS[$key])) {
                continue;
            }
            $value = $this->cast(self::DEFINITIONS[$key]['type'], $value);
            if (self::DEFINITIONS[$key]['type'] === 'select' && !array_key_exists($value, self::DEFINITIONS[$key]['options'] ?? [])) {
                continue;
            }
            if ($current[$key] === $value) {
                continue;
            }
            SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
            $changed[$key] = $value;
        }
        Cache::forget(self::CACHE_KEY);

        return $changed;
    }

    protected function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            default => mb_substr(trim((string) $value), 0, 500),
        };
    }
}
