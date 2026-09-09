<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseRequest;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CourseController extends Controller
{
    /**
     * Display a listing of courses for the authenticated faculty member.
     */
    public function index(Request $request): JsonResponse
    {
        $user     = $request->user();
        $cacheKey = "user:{$user->id}:courses";

        $courses = Cache::remember($cacheKey, 120, function () use ($user) {
            return $user->courses()
                ->withCount(['learningOutcomes', 'assessments', 'materials'])
                ->latest()
                ->get();
        });

        return response()->json([
            'data' => $courses,
        ]);
    }

    /**
     * Store a newly created course in storage.
     */
    public function store(CourseRequest $request): JsonResponse
    {
        $course = $request->user()->courses()->create($request->validated());
        $course->load(['learningOutcomes', 'materials']);

        Cache::forget("user:{$request->user()->id}:courses");

        return response()->json([
            'data'    => $course,
            'message' => 'Course created successfully',
        ], 201);
    }

    /**
     * Display the specified course with its learning outcomes and materials.
     */
    public function show(Request $request, Course $course): JsonResponse
    {
        if ($course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to course.',
            ], 403);
        }

        $course->load([
            'learningOutcomes',
            'materials',
            'program.outcomes',
            'assessments' => function ($q) {
                $q->latest()->withCount('questions');
            },
        ]);

        return response()->json([
            'data' => $course,
        ]);
    }

    /**
     * Update the specified course in storage.
     */
    public function update(CourseRequest $request, Course $course): JsonResponse
    {
        if ($course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to course.',
            ], 403);
        }

        $course->update($request->validated());
        $course->load(['learningOutcomes', 'materials', 'program.outcomes']);

        Cache::forget("user:{$request->user()->id}:courses");
        \App\Services\CoPoMappingValidatorService::invalidateCache($course->id);

        return response()->json([
            'data'    => $course,
            'message' => 'Course updated successfully',
        ]);
    }

    /**
     * Remove the specified course from storage.
     */
    public function destroy(Request $request, Course $course): JsonResponse
    {
        if ($course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to course.',
            ], 403);
        }

        // Delete any physical course material files from disk
        foreach ($course->materials as $material) {
            if ($material->file_path && Storage::disk('local')->exists($material->file_path)) {
                Storage::disk('local')->delete($material->file_path);
            }
        }

        $course->delete();

        Cache::forget("user:{$request->user()->id}:courses");

        return response()->json([
            'message' => 'Course deleted successfully',
        ]);
    }
}

