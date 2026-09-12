<?php

namespace Tests\Feature;

use App\Models\AiEvaluationDataset;
use App\Models\AiEvaluationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * STEP 44: `ai-evaluation:import` persists STEP 44 benchmark files through the STEP 35 dataset/run pipeline.
 * The AI service is faked so the metric values are deterministic.
 */
class AiEvaluationImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'name' => 'STEP44 benchmark – Difficulty (v1.0)', 'task' => 'DIFFICULTY_CLASSIFICATION', 'version' => 'v1.0', 'source' => 'CURATED_DATASET', 'split' => 'TEST',
            'description' => 'test import',
            'examples' => [
                ['input_data' => ['question' => 'Define a primary key.'], 'expected_output' => ['expected_difficulty' => 'EASY'], 'metadata' => ['benchmark_id' => 'Q1'], 'split' => 'DEV'],
                ['input_data' => ['question' => 'Prove the sorting lower bound.'], 'expected_output' => ['expected_difficulty' => 'HARD'], 'metadata' => ['benchmark_id' => 'Q2'], 'split' => 'TEST'],
                ['input_data' => ['question' => 'Explain ACID.'], 'expected_output' => ['expected_difficulty' => 'MEDIUM'], 'metadata' => ['benchmark_id' => 'Q3'], 'split' => 'TEST'],
            ],
        ];
    }

    private function fakeAi(): void
    {
        Http::fake([
            '*/api/v1/evaluation/models' => Http::response(['status' => 'success',
                'embedding_model' => ['model_name' => 'sentence-transformers/all-MiniLM-L6-v2', 'provider' => 'Hugging Face', 'model_type' => 'embedding', 'version' => 'configured', 'configuration' => []],
                'generation_model' => ['model_name' => 'not-configured', 'provider' => 'none', 'model_type' => 'generation', 'version' => 'configured', 'configuration' => ['configured' => false]],
                'engines' => [['model_name' => 'facultylens-question-analyzer', 'provider' => 'FacultyLens', 'model_type' => 'rule_engine', 'version' => '1.0.0', 'tasks' => ['DIFFICULTY_CLASSIFICATION']]],
                'prompt_versions' => [], 'thresholds' => []]),
            '*/api/v1/analyze-questions' => function ($request) {
                $qs = $request->data()['questions'];
                $levels = ['EASY', 'MEDIUM', 'MEDIUM'];
                return Http::response(['status' => 'success', 'total_questions' => count($qs), 'questions' => array_map(fn ($q, $i) => [
                    'number' => $q['number'], 'question' => $q['text'], 'classification' => ['question_type' => 'DESCRIPTIVE', 'confidence' => 0.9], 'topics' => [],
                    'difficulty' => ['level' => $levels[$i] ?? 'MEDIUM', 'method' => 'baseline'], 'cognitive_level' => ['level' => 'UNDERSTAND', 'method' => 'baseline'],
                ], $qs, array_keys($qs))]);
            },
        ]);
    }

    public function test_import_runs_synchronously_without_queuing_a_second_executor(): void
    {
        Queue::fake();
        $this->fakeAi();
        $user = User::factory()->create(['email' => 'admin@university.edu', 'role' => 'ADMIN']);
        $file = tempnam(sys_get_temp_dir(), 'step44');
        file_put_contents($file, json_encode($this->payload()));

        $this->artisan('ai-evaluation:import', ['path' => $file, '--user' => 'admin@university.edu', '--run' => true, '--sync' => true])
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $dataset = AiEvaluationDataset::where('task', 'DIFFICULTY_CLASSIFICATION')->firstOrFail();
        $this->assertSame($user->id, $dataset->created_by);
        $this->assertSame(3, $dataset->examples()->count());
        $this->assertSame('DEV', $dataset->examples()->orderBy('id')->first()->split);
        $run = AiEvaluationRun::where('dataset_id', $dataset->id)->firstOrFail();
        $this->assertSame(AiEvaluationRun::STATUS_COMPLETED, $run->status);
        $this->assertEqualsWithDelta(2 / 3, $run->summary['metrics']['accuracy'], 0.001);
        $this->assertSame(3, $run->predictions()->count());
        $this->assertSame('STEP 44 benchmark import ' . basename($file), $run->configuration['note']);
        unlink($file);
    }

    public function test_import_without_sync_queues_the_run(): void
    {
        Queue::fake();
        $this->fakeAi();
        User::factory()->create(['email' => 'faculty@university.edu']);
        $file = tempnam(sys_get_temp_dir(), 'step44');
        file_put_contents($file, json_encode($this->payload()));

        $this->artisan('ai-evaluation:import', ['path' => $file, '--user' => 'faculty@university.edu', '--run' => true])->assertSuccessful();

        Queue::assertPushed(\App\Jobs\RunAiEvaluationJob::class, 1);
        $this->assertSame(AiEvaluationRun::STATUS_PENDING, AiEvaluationRun::firstOrFail()->status);
        unlink($file);
    }

    public function test_replace_deletes_previous_dataset_with_same_name_and_version(): void
    {
        $this->fakeAi();
        User::factory()->create(['email' => 'faculty@university.edu']);
        $file = tempnam(sys_get_temp_dir(), 'step44');
        file_put_contents($file, json_encode($this->payload()));

        $this->artisan('ai-evaluation:import', ['path' => $file, '--user' => 'faculty@university.edu'])->assertSuccessful();
        $this->artisan('ai-evaluation:import', ['path' => $file, '--user' => 'faculty@university.edu', '--replace' => true])->assertSuccessful();

        $this->assertSame(1, AiEvaluationDataset::count());
        unlink($file);
    }

    public function test_unknown_user_fails(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'step44');
        file_put_contents($file, json_encode($this->payload()));
        $this->artisan('ai-evaluation:import', ['path' => $file, '--user' => 'nobody@university.edu'])->assertFailed();
        unlink($file);
    }
}
