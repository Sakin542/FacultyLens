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
    protected int $connectTimeout;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ai.url', 'http://127.0.0.1:8001'), '/');
        $this->apiKey = config('services.ai.api_key');
        $this->connectTimeout = (int) config('services.ai.connect_timeout', 10);
        $this->timeout = (int) config('services.ai.timeout', 120);
    }

    /**
     * Build HTTP client with base configuration, timeouts and optional authentication headers.
     */
    protected function client()
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->apiKey)) {
            $headers['X-AI-Service-Key'] = $this->apiKey;
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        return Http::connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withHeaders($headers);
    }

    /**
     * Send raw/cleaned document text to FastAPI for structured document extraction and NLP analysis.
     *
     * @param string $text
     * @param string $documentType
     * @return array
     * @throws Exception
     */
    public function sendDocument(string $text, string $documentType = 'question_paper'): array
    {
        return $this->analyze($text, $documentType);
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
            $this->handleHttpException($e, 'analyzing document text');
        } catch (RequestException $e) {
            $this->handleHttpException($e, 'analyzing document text');
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
            $this->handleHttpException($e, 'analyzing single question');
        } catch (RequestException $e) {
            $this->handleHttpException($e, 'analyzing single question');
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
            $this->handleHttpException($e, 'analyzing questions batch');
        } catch (RequestException $e) {
            $this->handleHttpException($e, 'analyzing questions batch');
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
            $this->handleHttpException($e, 'analyzing learning outcome alignment');
        } catch (RequestException $e) {
            $this->handleHttpException($e, 'analyzing learning outcome alignment');
        }
    }

    /**
     * Analyze Semantic Similarity & Potential Duplicates between current questions and historical exam questions.
     *
     * @param array $currentQuestions
     * @param array $previousQuestions
     * @param array|null $thresholds
     * @param int|null $topK
     * @param int|null $courseId
     * @return array
     * @throws Exception
     */
    public function analyzeSimilarity(
        array $currentQuestions,
        array $previousQuestions = [],
        ?array $thresholds = null,
        ?int $topK = null,
        ?int $courseId = null
    ): array {
        if (empty($currentQuestions)) {
            throw new Exception('At least one current question is required for similarity analysis.');
        }

        $formattedCurrent = [];
        foreach ($currentQuestions as $idx => $q) {
            if (is_string($q)) {
                $formattedCurrent[] = [
                    'id' => $idx + 1,
                    'question_number' => $idx + 1,
                    'text' => trim($q),
                ];
            } elseif (is_array($q)) {
                $formattedCurrent[] = [
                    'id' => $q['id'] ?? ($idx + 1),
                    'question_number' => $q['question_number'] ?? $q['number'] ?? ($idx + 1),
                    'text' => trim($q['text'] ?? $q['question_text'] ?? ''),
                    'question_type' => $q['question_type'] ?? $q['ai_question_type'] ?? null,
                    'cognitive_level' => $q['cognitive_level'] ?? $q['ai_cognitive_level'] ?? null,
                    'topics' => $q['topics'] ?? $q['ai_topics'] ?? [],
                ];
            }
        }

        $formattedPrevious = [];
        foreach ($previousQuestions as $idx => $pq) {
            if (is_string($pq)) {
                $formattedPrevious[] = [
                    'id' => $idx + 1,
                    'text' => trim($pq),
                ];
            } elseif (is_array($pq)) {
                $formattedPrevious[] = [
                    'id' => $pq['id'] ?? ($idx + 1),
                    'text' => trim($pq['text'] ?? $pq['question_text'] ?? ''),
                    'source_year' => $pq['source_year'] ?? null,
                    'source_assessment' => $pq['source_assessment'] ?? null,
                    'question_type' => $pq['question_type'] ?? null,
                    'cognitive_level' => $pq['cognitive_level'] ?? null,
                ];
            }
        }

        try {
            $payload = [
                'current_questions' => $formattedCurrent,
                'previous_questions' => $formattedPrevious,
            ];

            if ($courseId) {
                $payload['course_id'] = $courseId;
            }

            if (!empty($thresholds)) {
                $payload['thresholds'] = [
                    'duplicate' => (float) ($thresholds['duplicate'] ?? 0.85),
                    'high' => (float) ($thresholds['high'] ?? 0.70),
                    'moderate' => (float) ($thresholds['moderate'] ?? 0.50),
                    'top_k' => (int) ($thresholds['top_k'] ?? 5),
                ];
            }

            if ($topK) {
                $payload['top_k'] = $topK;
            }

            $response = $this->client()->post("{$this->baseUrl}/api/v1/analyze-similarity", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 422) {
                $errorData = $response->json();
                $message = $errorData['message'] ?? 'Validation failed in AI service.';
                throw new Exception($message);
            }

            Log::error('AI Service analyze-similarity error: ' . $response->status() . ' - ' . $response->body());
            throw new Exception('AI Service failed to analyze question semantic similarity.');
        } catch (ConnectionException $e) {
            $this->handleHttpException($e, 'analyzing semantic similarity');
        } catch (RequestException $e) {
            $this->handleHttpException($e, 'analyzing semantic similarity');
        }
    }

    /**
     * Evaluate overall examination quality across 6 pedagogical dimensions via FastAPI Assessment Quality Engine.
     *
     * @param array $assessmentData
     * @param array $questions
     * @param array $topics
     * @param array $learningOutcomes
     * @param array|null $weights
     * @param array|null $targets
     * @return array
     * @throws Exception
     */
    public function analyzeAssessmentQuality(
        array $assessmentData,
        array $questions,
        array $topics = [],
        array $learningOutcomes = [],
        ?array $weights = null,
        ?array $targets = null
    ): array {
        if (empty($questions)) {
            throw new Exception('At least one examination question is required for quality evaluation.');
        }

        $formattedQuestions = [];
        foreach ($questions as $idx => $q) {
            if (is_string($q)) {
                $formattedQuestions[] = [
                    'number' => $idx + 1,
                    'text' => trim($q),
                    'marks' => 1.0,
                ];
            } elseif (is_array($q)) {
                $formattedQuestions[] = [
                    'id' => $q['id'] ?? ($idx + 1),
                    'number' => $q['number'] ?? $q['question_number'] ?? ($idx + 1),
                    'text' => trim($q['text'] ?? $q['question_text'] ?? ''),
                    'marks' => (float) ($q['marks'] ?? 1.0),
                    'question_type' => $q['question_type'] ?? $q['ai_question_type'] ?? null,
                    'difficulty' => $q['difficulty'] ?? $q['difficulty_level'] ?? $q['ai_difficulty_level'] ?? null,
                    'cognitive_level' => $q['cognitive_level'] ?? $q['ai_cognitive_level'] ?? null,
                    'topics' => $q['topics'] ?? $q['ai_topics'] ?? [],
                    'learning_outcome_code' => $q['learning_outcome_code'] ?? $q['lo_code'] ?? null,
                    'similarity_score' => isset($q['similarity_score']) ? (float) $q['similarity_score'] : null,
                    'is_duplicate' => (bool) ($q['is_duplicate'] ?? false),
                ];
            }
        }

        $formattedTopics = [];
        foreach ($topics as $t) {
            if (is_string($t)) {
                $formattedTopics[] = ['name' => trim($t)];
            } elseif (is_array($t) && !empty($t['name'])) {
                $formattedTopics[] = [
                    'name' => trim($t['name']),
                    'weight' => isset($t['weight']) ? (float) $t['weight'] : null,
                ];
            }
        }

        $formattedLos = [];
        foreach ($learningOutcomes as $lo) {
            if (is_array($lo) && !empty($lo['code'])) {
                $formattedLos[] = [
                    'code' => trim($lo['code']),
                    'description' => trim($lo['description'] ?? ''),
                    'weight' => isset($lo['weight']) ? (float) $lo['weight'] : null,
                ];
            }
        }

        try {
            $payload = [
                'assessment' => [
                    'id' => $assessmentData['id'] ?? null,
                    'title' => $assessmentData['title'] ?? null,
                    'total_marks' => isset($assessmentData['total_marks']) ? (float) $assessmentData['total_marks'] : null,
                ],
                'questions' => $formattedQuestions,
                'topics' => $formattedTopics,
                'learning_outcomes' => $formattedLos,
            ];

            if (!empty($weights)) {
                $payload['weights'] = [
                    'topic' => (float) ($weights['topic'] ?? 20.0),
                    'lo' => (float) ($weights['lo'] ?? 20.0),
                    'difficulty' => (float) ($weights['difficulty'] ?? 15.0),
                    'cognitive' => (float) ($weights['cognitive'] ?? 15.0),
                    'question_diversity' => (float) ($weights['question_diversity'] ?? 15.0),
                    'marks' => (float) ($weights['marks'] ?? 15.0),
                ];
            }

            if (!empty($targets)) {
                $payload['difficulty_targets'] = [
                    'easy' => (float) ($targets['easy'] ?? 30.0),
                    'medium' => (float) ($targets['medium'] ?? 50.0),
                    'hard' => (float) ($targets['hard'] ?? 20.0),
                ];
            }

            $response = $this->client()->post("{$this->baseUrl}/api/v1/analyze-assessment-quality", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 422) {
                $errorData = $response->json();
                $message = $errorData['message'] ?? 'Validation failed in AI service.';
                throw new Exception($message);
            }

            Log::error('AI Service analyze-assessment-quality error: ' . $response->status() . ' - ' . $response->body());
            throw new Exception('AI Service failed to evaluate assessment quality.');
        } catch (ConnectionException $e) {
            $this->handleHttpException($e, 'evaluating assessment quality');
        } catch (RequestException $e) {
            $this->handleHttpException($e, 'evaluating assessment quality');
        }
    }

    /**
     * Generate prioritized, evidence-based recommendations for faculty review.
     *
     * @param array $payload Structured request with assessment metadata and prior analysis sections
     * @return array
     * @throws Exception
     */
    public function generateRecommendations(array $payload): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/api/v1/generate-recommendations", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 422) {
                $errorData = $response->json();
                $message = $errorData['message'] ?? 'Validation failed in AI service.';
                throw new Exception($message);
            }

            Log::error('AI Service generate-recommendations error: ' . $response->status() . ' - ' . $response->body());
            throw new Exception('AI Service failed to generate recommendations.');
        } catch (ConnectionException $e) {
            $this->handleHttpException($e, 'generating recommendations');
        } catch (RequestException $e) {
            $this->handleHttpException($e, 'generating recommendations');
        }
    }

    /**
     * Run unified AI Assessment Analysis across all modules.
     *
     * @param array $payload
     * @return array
     * @throws Exception
     */
    public function analyzeAssessment(array $payload): array
    {
        if (empty($payload['questions'])) {
            throw new Exception('At least one question is required for unified assessment analysis.');
        }

        try {
            $response = $this->client()->post("{$this->baseUrl}/api/v1/analyze-assessment", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 422) {
                $errorData = $response->json();
                $message = $errorData['message'] ?? 'Validation failed in AI service.';
                throw new Exception($message);
            }

            Log::error('AI Service analyze-assessment error: ' . $response->status() . ' - ' . $response->body());
            throw new Exception('AI Service failed to perform unified assessment analysis.');
        } catch (ConnectionException $e) {
            $this->handleHttpException($e, 'performing unified assessment analysis');
        } catch (RequestException $e) {
            $this->handleHttpException($e, 'performing unified assessment analysis');
        }
    }

    /**
     * Translate HTTP / Connection / Request exceptions into descriptive domain exceptions with timeout detection.
     *
     * @param Exception $e
     * @param string $action
     * @return void
     * @throws Exception
     */
    protected function handleHttpException(Exception $e, string $action): void
    {
        $msg = $e->getMessage();
        Log::error("AI Service {$action} error: {$msg}");

        if (
            str_contains(strtolower($msg), 'timed out') ||
            str_contains(strtolower($msg), 'timeout') ||
            str_contains($msg, 'cURL error 28')
        ) {
            throw new Exception("AI Service request timed out while {$action}.");
        }

        if ($e instanceof ConnectionException) {
            throw new Exception("AI Service is currently unavailable while {$action}.");
        }

        throw new Exception("AI Service request encountered an error while {$action}.");
    }
}





