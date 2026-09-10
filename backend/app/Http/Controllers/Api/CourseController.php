<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseRequest;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Services\CourseAccessService;
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

        // STEP 34: owned + actively shared courses (authorization in SQL), each tagged with the caller's role.
        $courses = Cache::remember($cacheKey, 120, function () use ($user) {
            $access = app(CourseAccessService::class);

            return Course::query()->accessibleBy($user)
                ->withCount(['learningOutcomes', 'assessments', 'materials'])
                ->latest()
                ->get()
                ->each(fn (Course $c) => $c->setAttribute('current_role', $access->roleFor($user, $c)));
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
        if (!$request->user()->can('view', $course)) {
            return response()->json([
                'message' => 'Unauthorized access to course.',
            ], 403);
        }

        $course->load([
            'learningOutcomes',
            'materials',
            'program.outcomes',
            'user:id,name,email',
            'assessments' => function ($q) {
                $q->latest()->withCount('questions');
            },
        ]);

        $access = app(CourseAccessService::class);
        $course->setAttribute('current_role', $access->roleFor($request->user(), $course));
        $course->setAttribute('permissions', $access->permissions($request->user(), $course));

        return response()->json([
            'data' => $course,
        ]);
    }

    /**
     * Update the specified course in storage.
     */
    public function update(CourseRequest $request, Course $course): JsonResponse
    {
        if (!$request->user()->can('update', $course)) {
            return response()->json([
                'message' => 'Unauthorized access to course.',
            ], 403);
        }

        // Collaborators may edit content but never re-assign ownership/program through this endpoint.
        $data = $request->validated();
        unset($data['user_id']);
        $course->update($data);
        $course->load(['learningOutcomes', 'materials', 'program.outcomes']);

        self::forgetCourseListCaches($course);
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
        if (!$request->user()->can('delete', $course)) {
            return response()->json([
                'message' => 'Unauthorized access to course.',
            ], 403);
        }

        self::forgetCourseListCaches($course);

        // Delete any physical course material files from disk
        foreach ($course->materials as $material) {
            if ($material->file_path && Storage::disk('local')->exists($material->file_path)) {
                Storage::disk('local')->delete($material->file_path);
            }
        }

        $course->delete();

        return response()->json([
            'message' => 'Course deleted successfully',
        ]);
    }

    /**
     * STEP 34: the course list is cached per user; owner and every member must be invalidated.
     */
    public static function forgetCourseListCaches(Course $course): void
    {
        Cache::forget("user:{$course->user_id}:courses");
        foreach (CourseCollaborator::where('course_id', $course->id)->pluck('user_id') as $memberId) {
            Cache::forget("user:{$memberId}:courses");
        }
    }
}

