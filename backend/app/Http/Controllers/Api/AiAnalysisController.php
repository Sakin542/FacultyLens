<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiAnalysisController extends Controller
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Check AI Service health status.
     */
    public function health(): JsonResponse
    {
        $health = $this->aiService->checkHealth();
        $isOk = ($health['status'] ?? '') === 'ok';

        return response()->json([
            'data' => $health,
        ], $isOk ? 200 : 503);
    }

    /**
     * Analyze academic document text via the Python FastAPI microservice.
     */
    public function analyze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['nullable', 'string', 'max:100'],
            'text' => ['required', 'string', 'min:3', 'max:50000'],
        ], [
            'text.required' => 'Academic text is required for analysis.',
            'text.min' => 'Academic text must be at least 3 characters long.',
            'text.max' => 'Academic text cannot exceed 50,000 characters.',
        ]);

        $documentType = $validated['document_type'] ?? 'question_paper';
        $text = $validated['text'];

        try {
            $analysisResult = $this->aiService->analyze($text, $documentType);

            return response()->json([
                'status' => 'success',
                'message' => 'Academic text analyzed successfully.',
                'data' => $analysisResult,
            ], 200);
        } catch (Exception $e) {
            Log::error('AI Analysis failed in controller: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }
}

