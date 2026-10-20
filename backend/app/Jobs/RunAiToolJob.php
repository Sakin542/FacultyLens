<?php

namespace App\Jobs;

use App\Models\AiToolJob;
use App\Services\AiService;
use App\Services\AuditLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs one AI toolkit request (handwriting OCR or lecture transcription) on the dedicated `ai-tools` queue.
 * The uploaded file is deleted when the job ends, whether it succeeded or not; only the text result is kept.
 */
class RunAiToolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $jobId)
    {
        $this->onQueue('ai-tools');
        $this->timeout = max(60, (int) config('services.ai.toolkit_timeout', 1800));
    }

    public function handle(AiService $ai, AuditLogService $audit): void
    {
        $job = AiToolJob::query()->find($this->jobId);
        if (!$job || in_array($job->status, ['completed', 'failed'], true)) {
            return; // deleted, or already finished by an earlier delivery
        }
        $disk = Storage::disk('local');
        if (!$job->input_path || !$disk->exists($job->input_path)) {
            $job->forceFill(['status' => 'failed', 'error' => 'The uploaded file is no longer available.', 'completed_at' => now()])->save();

            return;
        }

        $job->forceFill(['status' => 'processing', 'started_at' => now(), 'error' => null])->save();
        try {
            $path = $disk->path($job->input_path);
            $result = match ($job->tool) {
                'ocr' => $ai->ocr($path, $job->input_filename),
                'transcription' => $ai->transcribe($path, $job->input_filename, $job->language, $job->result['terms'] ?? null),
                'study_pack' => $ai->studyPack((string) $disk->get($job->input_path), $job->input_filename, (int) ($job->result['num_questions'] ?? 5)),
            };

            $job->forceFill([
                'status' => 'completed',
                'result_text' => $job->tool === 'study_pack' ? self::studyPackText($result) : (string) ($result['text'] ?? ''),
                'result' => collect($result + ['terms' => $job->result['terms'] ?? null])->only(['segments', 'pages', 'model', 'provider', 'fallback_reason', 'corrected_lines', 'proofread_model', 'terms', 'duration_seconds', 'line_count', 'note', 'language',
                    'title', 'summary', 'summary_method', 'key_points', 'keywords', 'quiz', 'quiz_method', 'quiz_rejected'])->all(),
                'completed_at' => now(),
            ])->save();
            $audit->log('AI_TOOL_COMPLETED', $job, $job->id, ['tool' => $job->tool, 'model' => $result['model'] ?? null], $job->user);
        } catch (Throwable $e) {
            Log::warning('AI toolkit job failed', ['job' => $job->id, 'tool' => $job->tool, 'error' => class_basename($e)]);
            // AiService exceptions carry safe, user-facing messages; anything else is summarised
            $message = get_class($e) === \Exception::class ? $e->getMessage() : 'The AI tool failed.';
            $job->forceFill(['status' => 'failed', 'error' => mb_substr($message, 0, 255), 'completed_at' => now()])->save();
            $audit->log('AI_TOOL_FAILED', $job, $job->id, ['tool' => $job->tool], $job->user);
        } finally {
            $disk->delete($job->input_path);
            $job->forceFill(['input_path' => null])->save();
        }
    }

    /** Plain-text version of a study pack for copy/download. */
    public static function studyPackText(array $pack): string
    {
        $lines = [];
        if (!empty($pack['title'])) {
            $lines[] = mb_strtoupper((string) $pack['title']);
            $lines[] = '';
        }
        $lines[] = 'SUMMARY';
        $lines[] = (string) ($pack['summary'] ?? '');
        $lines[] = '';
        $lines[] = 'KEY POINTS';
        foreach ((array) ($pack['key_points'] ?? []) as $point) {
            $lines[] = '- '.$point;
        }
        $lines[] = '';
        $lines[] = 'QUIZ (draft — check before use)';
        foreach (array_values((array) ($pack['quiz'] ?? [])) as $i => $q) {
            $lines[] = ($i + 1).'. '.($q['question'] ?? '');
            $lines[] = '   Answer: '.($q['answer'] ?? '');
        }

        return implode("\n", $lines);
    }

    public function failed(Throwable $e): void
    {
        $job = AiToolJob::query()->find($this->jobId);
        if ($job && !in_array($job->status, ['completed', 'failed'], true)) {
            $job->forceFill(['status' => 'failed', 'error' => 'The job was stopped before it finished (timeout or worker restart).', 'completed_at' => now()])->save();
            if ($job->input_path) {
                Storage::disk('local')->delete($job->input_path);
                $job->forceFill(['input_path' => null])->save();
            }
        }
    }
}
