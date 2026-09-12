<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Explainability\ExplainabilityException;
use App\Services\Explainability\ExplanationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STEP 45: AI Explainability & Transparency.
 *
 * GET  /api/ai-results/{type}/{id}/explanation  — what / why / evidence / method / confidence / limitations / review
 * GET  /api/ai-results/{type}/{id}/reviews      — faculty review history
 * POST /api/ai-results/{type}/{id}/review       — accept / reject / mark reviewed (never changes the AI value)
 * POST /api/ai-results/{type}/{id}/override     — faculty override of a faculty-controlled field + reason
 * POST /api/ai-results/{type}/{id}/events       — audited view events (result viewed, evidence viewed, source opened)
 *
 * Explanations are derived from stored results and existing rules; no prompts, chain-of-thought or secrets are exposed.
 */
class AiExplainabilityController extends Controller
{
    public function __construct(protected ExplanationService $service) {}

    public function explanation(Request $request, string $type, int $id): JsonResponse
    {
        try {
            $data = $this->service->explain($request->user(), $type, $id);
        } catch (ExplainabilityException $e) {
            return $this->error($e);
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function reviews(Request $request, string $type, int $id): JsonResponse
    {
        try {
            $data = $this->service->history($request->user(), $type, $id);
        } catch (ExplainabilityException $e) {
            return $this->error($e);
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function review(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:ACCEPTED,REJECTED,REVIEWED,accepted,rejected,reviewed'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->service->review($request->user(), $type, $id, $data['action'], $data['comment'] ?? null);
        } catch (ExplainabilityException $e) {
            return $this->error($e);
        }

        return response()->json(['status' => 'success', 'message' => 'Your decision has been recorded. AI assists; faculty decides.', 'data' => $result]);
    }

    public function override(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'array'],
            'value.label' => ['nullable', 'string', 'max:60'],
            'value.learning_outcome_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:60'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->service->override($request->user(), $type, $id, $data['value'], $data['reason'], $data['comment'] ?? null);
        } catch (ExplainabilityException $e) {
            return $this->error($e);
        }

        return response()->json(['status' => 'success', 'message' => 'Your override has been applied to the faculty-controlled value. The AI result is kept for reference.', 'data' => $result]);
    }

    public function event(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'max:40'],
            'meta' => ['nullable', 'array'],
        ]);

        try {
            $this->service->recordEvent($request->user(), $type, $id, $data['action'], $data['meta'] ?? []);
        } catch (ExplainabilityException $e) {
            return $this->error($e);
        }

        return response()->json(['status' => 'success'], 202);
    }

    protected function error(ExplainabilityException $e): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatus());
    }
}
