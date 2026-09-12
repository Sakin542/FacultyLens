<?php

namespace App\Console\Commands;

use App\Models\AiEvaluationDataset;
use App\Models\User;
use App\Services\AiEvaluationService;
use Illuminate\Console\Command;

/**
 * STEP 44: import a STEP 44 benchmark file (exported by `python -m evaluation.export_laravel`) as a STEP 35
 * evaluation dataset, validate it and optionally run the evaluation so that results, predictions, gates and
 * regression comparisons are persisted in the ai_evaluation_* tables and visible on /ai-evaluation.
 *
 *   php artisan ai-evaluation:import ../ai-service/evaluation/results/laravel_import --user=admin@example.edu --run --sync
 *
 * `--sync` executes the run in-process (no queue worker needed); without it the run is queued as usual.
 * Datasets are created for the given user only; the ordinary STEP 35 authorization applies afterwards.
 */
class ImportAiEvaluationDatasetCommand extends Command
{
    protected $signature = 'ai-evaluation:import {path : JSON file or directory of files} {--user= : Owner e-mail (defaults to the first admin)} {--run : Start an evaluation run after import} {--sync : Execute the run immediately instead of queuing it} {--replace : Delete an existing dataset with the same name and version first}';

    protected $description = 'Import STEP 44 benchmark datasets into the STEP 35 AI evaluation tables and optionally run them';

    public function handle(AiEvaluationService $service): int
    {
        $user = $this->resolveUser();
        if (!$user) {
            $this->error('No user found. Pass --user=<email> of an existing faculty/admin account.');
            return self::FAILURE;
        }
        $files = $this->resolveFiles((string) $this->argument('path'));
        if ($files === []) {
            $this->error('No JSON files found at the given path.');
            return self::FAILURE;
        }

        $rows = [];
        foreach ($files as $file) {
            $payload = json_decode((string) file_get_contents($file), true);
            if (!is_array($payload) || empty($payload['task']) || empty($payload['name'])) {
                $this->warn("Skipping {$file}: not a dataset payload (needs name, task, examples).");
                continue;
            }
            if (!in_array($payload['task'], (array) config('ai_evaluation.tasks'), true)) {
                $this->warn("Skipping {$file}: unsupported task {$payload['task']}.");
                continue;
            }
            if ($this->option('replace')) {
                AiEvaluationDataset::where('name', $payload['name'])->where('version', $payload['version'] ?? 'v1')->where('created_by', $user->id)->get()->each->delete();
            }
            $dataset = $service->createDataset($user, [
                'name' => $payload['name'], 'description' => $payload['description'] ?? null, 'task' => $payload['task'], 'version' => $payload['version'] ?? 'v1',
                'source' => $payload['source'] ?? 'CURATED_DATASET', 'split' => $payload['split'] ?? 'TEST',
            ]);
            $examples = array_map(fn ($e) => [
                'input_data' => (array) ($e['input_data'] ?? []), 'expected_output' => (array) ($e['expected_output'] ?? []), 'metadata' => $e['metadata'] ?? null,
                'source' => $e['source'] ?? null, 'split' => $e['split'] ?? null,
            ], (array) ($payload['examples'] ?? []));
            $added = $service->addExamples($user, $dataset, $examples);
            $report = $service->validateDataset($dataset);
            $row = ['task' => $payload['task'], 'dataset_id' => $dataset->id, 'examples' => $added['added'], 'skipped' => $added['skipped_duplicates'], 'valid' => $report['is_valid'] ? 'yes' : 'NO', 'run' => '—', 'status' => '—', 'headline' => '—'];

            if ($this->option('run') && $report['is_valid']) {
                $run = $service->startRun($user, $dataset, ['note' => 'STEP 44 benchmark import ' . basename($file), 'dispatch' => !$this->option('sync')]);
                $row['run'] = $run->id;
                if ($this->option('sync')) {
                    try {
                        $service->executeRun($run);
                    } catch (\Throwable $e) {
                        $this->warn("Run {$run->id} failed: {$e->getMessage()}");
                    }
                    $run->refresh();
                    $row['status'] = $run->status . ($run->gate_status ? " / {$run->gate_status}" : '');
                    $row['headline'] = isset($run->summary['headline_metric']) ? $run->summary['headline_metric'] . ' = ' . ($run->summary['headline_value'] ?? '—') : '—';
                } else {
                    $row['status'] = $run->status . ' (queued)';
                }
            } elseif ($this->option('run')) {
                $row['status'] = 'validation failed: ' . json_encode(array_slice($report['problems'], 0, 2));
            }
            $rows[] = $row;
        }

        $this->table(['Task', 'Dataset', 'Examples', 'Dup', 'Valid', 'Run', 'Status', 'Headline'], array_map('array_values', $rows));
        $this->info('Imported ' . count($rows) . ' dataset(s) for ' . $user->email . '. Results are visible under /ai-evaluation and GET /api/ai/evaluation/runs.');

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        if ($email = $this->option('user')) {
            return User::where('email', $email)->first();
        }
        return User::whereRaw('UPPER(role) = ?', ['ADMIN'])->orderBy('id')->first() ?? User::orderBy('id')->first();
    }

    /** @return string[] */
    private function resolveFiles(string $path): array
    {
        if (is_dir($path)) {
            $files = glob(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [];
            sort($files);
            return $files;
        }
        return is_file($path) ? [$path] : [];
    }
}
