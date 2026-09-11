<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_unauthenticated_user_cannot_access_ai_analysis(): void
    {
        $response = $this->postJson('/api/ai/analyze', [
            'document_type' => 'question_paper',
            'text' => '1. Explain polymorphism.',
        ]);

        $response->assertStatus(401);
    }

    public function test_validation_fails_on_empty_text(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze', [
            'document_type' => 'question_paper',
            'text' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['text']);
    }

    public function test_authenticated_user_can_analyze_academic_text_successfully(): void
    {
        $mockFastApiResponse = [
            'status' => 'success',
            'document_type' => 'question_paper',
            'analysis' => [
                'character_count' => 120,
                'word_count' => 20,
                'sentence_count' => 2,
                'paragraph_count' => 2,
                'questions_detected' => 2,
                'questions' => [
                    ['number' => 1, 'text' => 'Explain database normalization.'],
                    ['number' => 2, 'text' => 'Compare SQL and NoSQL databases.'],
                ],
                'keywords' => ['database', 'sql', 'nosql'],
                'embeddings_generated' => true,
                'embedding_dimension' => 384,
                'model' => 'sentence-transformers/all-MiniLM-L6-v2',
            ],
        ];

        Http::fake([
            '*/api/v1/analyze' => Http::response($mockFastApiResponse, 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze', [
            'document_type' => 'question_paper',
            'text' => "1. Explain database normalization.\n2. Compare SQL and NoSQL databases.",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => $mockFastApiResponse,
            ]);
    }

    public function test_handles_fastapi_connection_failure_gracefully(): void
    {
        Http::fake([
            '*/api/v1/analyze' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host');
            },
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze', [
            'document_type' => 'question_paper',
            'text' => '1. Explain database normalization.',
        ]);

        $response->assertStatus(502)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_ai_health_endpoint(): void
    {
        Http::fake([
            '*/health' => Http::response([
                'status' => 'ok',
                'service' => 'FacultyLens AI Service',
                'model' => 'sentence-transformers/all-MiniLM-L6-v2',
                'model_loaded' => true,
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/ai/health');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'ok',
                    'service' => 'FacultyLens AI Service',
                    'model_loaded' => true,
                ],
            ]);
    }

    public function test_end_to_end_real_academic_text_with_live_service(): void
    {
        $this->requireLiveAiService();

        $academicText = "1. Explain database normalization.\n2. Describe the difference between SQL and NoSQL databases.\n3. Compare relational and non-relational database systems.";

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze', [
            'document_type' => 'question_paper',
            'text' => $academicText,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.document_type', 'question_paper')
            ->assertJsonPath('data.analysis.questions_detected', 3)
            ->assertJsonPath('data.analysis.embeddings_generated', true)
            ->assertJsonPath('data.analysis.embedding_dimension', 384);
    }
}

