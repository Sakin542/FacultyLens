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
}

