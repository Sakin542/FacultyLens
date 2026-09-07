<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LearningOutcomeRequest;
use App\Models\Course;
use App\Models\LearningOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearningOutcomeController extends Controller
{
    /**
     * Display a listing of learning outcomes for a given course.
     */
    public function index(Request $request, Course $course): JsonResponse
    {
        if ($course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to course learning outcomes.',
            ], 403);
        }

        $outcomes = $course->learningOutcomes()->orderBy('sort_order')->get();

        return response()->json([
            'data' => $outcomes,
        ]);
    }

    /**
     * Store a newly created learning outcome for a course.
     */
    public function store(LearningOutcomeRequest $request, Course $course): JsonResponse
    {
        if ($course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to course.',
            ], 403);
        }

        $data = $request->validated();
        if (empty($data['sort_order'])) {
            $data['sort_order'] = ($course->learningOutcomes()->max('sort_order') ?? 0) + 1;
        }

        $outcome = $course->learningOutcomes()->create($data);

        return response()->json([
            'data' => $outcome,
            'message' => 'Learning outcome added successfully',
        ], 201);
    }

    /**
     * Update the specified learning outcome.
     */
    public function update(LearningOutcomeRequest $request, LearningOutcome $learningOutcome): JsonResponse
    {
        if ($learningOutcome->course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to learning outcome.',
            ], 403);
        }

        $learningOutcome->update($request->validated());

        return response()->json([
            'data' => $learningOutcome,
            'message' => 'Learning outcome updated successfully',
        ]);
    }

    /**
     * Remove the specified learning outcome.
     */
    public function destroy(Request $request, LearningOutcome $learningOutcome): JsonResponse
    {
        if ($learningOutcome->course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to learning outcome.',
            ], 403);
        }

        $learningOutcome->delete();

        return response()->json([
            'message' => 'Learning outcome deleted successfully',
        ]);
    }
}

