<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ai.url', 'http://127.0.0.1:8001'), '/');
        $this->apiKey = config('services.ai.api_key');
        $this->timeout = (int) config('services.ai.timeout', 30);
    }

    /**
     * Build HTTP client with base configuration and optional authentication headers.
     */
    protected function client()
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->apiKey)) {
            $headers['X-AI-Service-Key'] = $this->apiKey;
        }

        return Http::timeout($this->timeout)
            ->withHeaders($headers);
    }

    /**
     * Check health and model loading status of the AI microservice.
     */
    public function checkHealth(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/health");

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'status' => 'error',
                'message' => 'AI Service responded with status ' . $response->status(),
            ];
        } catch (ConnectionException $e) {
            Log::warning('AI Service health check connection failed: ' . $e->getMessage());
            return [
                'status' => 'unavailable',
                'message' => 'AI Service is currently unreachable.',
            ];
        } catch (Exception $e) {
            Log::error('AI Service health check error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Failed to connect to AI Service.',
            ];
        }
    }

    /**
     * Send academic text to FastAPI for structured NLP analysis.
     *
     * @param string $text
     * @param string $documentType
     * @return array
     * @throws Exception
     */
    public function analyze(string $text, string $documentType = 'question_paper'): array
    {
        $trimmed = trim($text);
        if (empty($trimmed)) {
            throw new Exception('Text content cannot be empty.');
        }

        try {
            $response = $this->client()->post("{$this->baseUrl}/api/v1/analyze", [
                'document_type' => $documentType,
                'text' => $trimmed,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            // Handle 422 validation from AI service
            if ($response->status() === 422) {
                $errorData = $response->json();
                $message = $errorData['message'] ?? 'Input validation failed in AI service.';
                Log::warning('AI Service validation error: ' . json_encode($errorData));
                throw new Exception($message);
            }

            Log::error('AI Service returned error status: ' . $response->status() . ' - ' . $response->body());
            throw new Exception('AI Service failed to process the document.');
        } catch (ConnectionException $e) {
            Log::error('AI Service connection error: ' . $e->getMessage());
            throw new Exception('AI Service is currently unavailable. Please ensure the AI service is running.');
        } catch (RequestException $e) {
            Log::error('AI Service request exception: ' . $e->getMessage());
            throw new Exception('AI Service request timed out or encountered an error.');
        }
    }

    /**
     * Preprocess and clean academic text through FastAPI.
     *
     * @param string $text
     * @return array
     * @throws Exception
     */
    public function preprocess(string $text): array
    {
        $trimmed = trim($text);
        if (empty($trimmed)) {
            throw new Exception('Text content cannot be empty.');
        }

        try {
            $response = $this->client()->post("{$this->baseUrl}/api/v1/preprocess", [
                'text' => $trimmed,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('AI Service preprocess error: ' . $response->status());
            throw new Exception('AI Service failed to preprocess text.');
        } catch (ConnectionException $e) {
            Log::error('AI Service connection error: ' . $e->getMessage());
            throw new Exception('AI Service is unreachable.');
        }
    }

    /**
     * Generate text embeddings via FastAPI for development/internal use.
     *
     * @param string $text
     * @param bool $returnVector
     * @return array
     * @throws Exception
     */
    public function generateEmbedding(string $text, bool $returnVector = false): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/api/v1/embedding", [
                'text' => trim($text),
                'return_vector' => $returnVector,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            throw new Exception('AI Service failed to generate embedding.');
        } catch (ConnectionException $e) {
            throw new Exception('AI Service is unreachable.');
        }
    }

    /**
     * Analyze a single academic question across classification, topics, difficulty, and Bloom's level.
     *
     * @param string $question
     * @param array $courseTopics
     * @return array
     * @throws Exception
     */
    public function analyzeQuestion(string $question, array $courseTopics = []): array
    {
        $trimmed = trim($question);
        if (empty($trimmed)) {
            throw new Exception('Question text cannot be empty.');
        }

        try {
            $payload = [
                'question' => $trimmed,
            ];
            if (!empty($courseTopics)) {
                $payload['course_topics'] = array_values(array_filter($courseTopics));
            }

            $response = $this->client()->post("{$this->baseUrl}/api/v1/analyze-question", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 422) {
                $errorData = $response->json();
                $message = $errorData['message'] ?? 'Validation failed in AI service.';
                throw new Exception($message);
            }

            Log::error('AI Service analyze-question error: ' . $response->status() . ' - ' . $response->body());
            throw new Exception('AI Service failed to analyze question.');
        } catch (ConnectionException $e) {
            Log::error('AI Service connection error: ' . $e->getMessage());
            throw new Exception('AI Service is currently unavailable.');
        } catch (RequestException $e) {
            Log::error('AI Service request exception: ' . $e->getMessage());
            throw new Exception('AI Service request timed out or encountered an error.');
        }
    }

    /**
     * Analyze a batch of academic questions.
     *
     * @param array $questions Array of ['number' => int|null, 'text' => string] or strings
     * @param array $courseTopics
     * @return array
     * @throws Exception
     */
    public function analyzeQuestions(array $questions, array $courseTopics = []): array
    {
        if (empty($questions)) {
            throw new Exception('Questions array cannot be empty.');
        }

        $formattedQuestions = [];
        foreach ($questions as $idx => $q) {
            if (is_string($q)) {
                $formattedQuestions[] = [
                    'number' => $idx + 1,
                    'text' => trim($q),
                ];
            } elseif (is_array($q)) {
                $formattedQuestions[] = [
                    'number' => $q['number'] ?? ($idx + 1),
                    'text' => trim($q['text'] ?? $q['question_text'] ?? ''),
                ];
            }
        }

        try {
            $payload = [
                'questions' => $formattedQuestions,
            ];
            if (!empty($courseTopics)) {
                $payload['course_topics'] = array_values(array_filter($courseTopics));
            }

            $response = $this->client()->post("{$this->baseUrl}/api/v1/analyze-questions", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 422) {
                $errorData = $response->json();
                $message = $errorData['message'] ?? 'Validation failed in AI service.';
                throw new Exception($message);
            }

            Log::error('AI Service analyze-questions error: ' . $response->status() . ' - ' . $response->body());
            throw new Exception('AI Service failed to analyze questions batch.');
        } catch (ConnectionException $e) {
            Log::error('AI Service connection error: ' . $e->getMessage());
            throw new Exception('AI Service is currently unavailable.');
        } catch (RequestException $e) {
            Log::error('AI Service request exception: ' . $e->getMessage());
            throw new Exception('AI Service request timed out or encountered an error.');
        }
    }

    /**
     * Analyze Learning Outcome Alignment for a set of examination questions against course LOs.
     *
     * @param array $learningOutcomes
     * @param array $questions
     * @param array|null $thresholds
     * @param int|null $courseId
     * @return array
     * @throws Exception
     */
    public function analyzeAlignment(
        array $learningOutcomes,
        array $questions,
        ?array $thresholds = null,
        ?int $courseId = null
    ): array {
        if (empty($learningOutcomes)) {
            throw new Exception('At least one learning outcome is required for alignment analysis.');
        }

        if (empty($questions)) {
            throw new Exception('At least one question is required for alignment analysis.');
        }

        $formattedLos = [];
        foreach ($learningOutcomes as $idx => $lo) {
            if (is_string($lo)) {
                $formattedLos[] = [
                    'id' => $idx + 1,
                    'code' => 'LO' . ($idx + 1),
                    'description' => trim($lo),
                ];
            } elseif (is_array($lo)) {
                $formattedLos[] = [
                    'id' => $lo['id'] ?? ($idx + 1),
                    'code' => $lo['code'] ?? ('LO' . ($idx + 1)),
                    'description' => trim($lo['description'] ?? ''),
                ];
            }
        }

        $formattedQuestions = [];
        foreach ($questions as $idx => $q) {
            if (is_string($q)) {
                $formattedQuestions[] = [
                    'id' => $idx + 1,
                    'number' => $idx + 1,
                    'text' => trim($q),
                    'topics' => [],
                ];
            } elseif (is_array($q)) {
                $formattedQuestions[] = [
                    'id' => $q['id'] ?? ($idx + 1),
                    'number' => $q['number'] ?? $q['question_number'] ?? ($idx + 1),
                    'text' => trim($q['text'] ?? $q['question_text'] ?? ''),
                    'topics' => $q['topics'] ?? $q['ai_topics'] ?? [],
                ];
            }
        }

        try {
            $payload = [
                'learning_outcomes' => $formattedLos,
                'questions' => $formattedQuestions,
            ];

            if ($courseId) {
                $payload['course_id'] = $courseId;
            }

            if (!empty($thresholds)) {
                $payload['thresholds'] = [
                    'strong' => (float) ($thresholds['strong'] ?? 0.70),
                    'weak' => (float) ($thresholds['weak'] ?? 0.50),
                ];
            }

            $response = $this->client()->post("{$this->baseUrl}/api/v1/analyze-alignment", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 422) {
                $errorData = $response->json();
                $message = $errorData['message'] ?? 'Validation failed in AI service.';
                throw new Exception($message);
            }

            Log::error('AI Service analyze-alignment error: ' . $response->status() . ' - ' . $response->body());
            throw new Exception('AI Service failed to analyze learning outcome alignment.');
        } catch (ConnectionException $e) {
            Log::error('AI Service connection error: ' . $e->getMessage());
            throw new Exception('AI Service is currently unavailable.');
        } catch (RequestException $e) {
            Log::error('AI Service request exception: ' . $e->getMessage());
            throw new Exception('AI Service request timed out or encountered an error.');
        }
    }
}



