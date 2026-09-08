<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssessmentRequest;
use App\Models\Assessment;
use App\Models\Course;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AssessmentController extends Controller
{
    protected AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    /**
     * Display a listing of assessments for a specific course or for all courses of the faculty.
     */
    public function index(Request $request, ?Course $course = null): JsonResponse
    {
        $user = $request->user();

        if ($course && $course->exists) {
            if ($course->user_id !== $user->id) {
                return response()->json([
                    'message' => 'Unauthorized access to course assessments.',
                ], 403);
            }

            $assessments = $course->assessments()
                ->with(['questionPaper'])
                ->withCount('questions')
                ->latest()
                ->get();

            return response()->json([
                'data' => $assessments,
            ]);
        }

        // Return all assessments for the authenticated faculty across all their courses
        $query = Assessment::whereHas('course', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
            ->with(['course', 'questionPaper'])
            ->withCount('questions');

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage     = min(max((int) $request->get('per_page', 20), 1), 100);
        $assessments = $query->latest()->paginate($perPage);

        return response()->json($assessments);
    }

    /**
     * Store a newly created assessment for a specific course.
     */
    public function store(AssessmentRequest $request, Course $course): JsonResponse
    {
        if ($course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to create assessment for this course.',
            ], 403);
        }

        $validated = $request->validated();
        $assessment = $course->assessments()->create($validated);
        $assessment->load(['course', 'questionPaper'])->loadCount('questions');

        // Audit log assessment creation event
        $this->auditLogService->log(
            'ASSESSMENT_CREATED',
            $assessment,
            $assessment->id,
            [
                'title' => $assessment->title,
                'course_id' => $course->id,
                'type' => $assessment->type,
                'total_marks' => $assessment->total_marks,
            ],
            $request->user()
        );

        return response()->json([
            'data' => $assessment,
            'message' => 'Assessment created successfully',
        ], 201);
    }

    /**
     * Display the specified assessment.
     */
    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        if ($assessment->course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to assessment.',
            ], 403);
        }

        $assessment->load([
            'course',
            'questionPaper',
            'questions.learningOutcome',
        ])->loadCount('questions');

        return response()->json([
            'data' => $assessment,
        ]);
    }

    /**
     * Update the specified assessment in storage.
     */
    public function update(AssessmentRequest $request, Assessment $assessment): JsonResponse
    {
        if ($assessment->course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to update assessment.',
            ], 403);
        }

        // Only update validated fields, course_id cannot be changed
        $assessment->update($request->validated());
        $assessment->load(['course', 'questionPaper'])->loadCount('questions');

        return response()->json([
            'data' => $assessment,
            'message' => 'Assessment updated successfully',
        ]);
    }

    /**
     * Remove the specified assessment from storage.
     */
    public function destroy(Request $request, Assessment $assessment): JsonResponse
    {
        if ($assessment->course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to delete assessment.',
            ], 403);
        }

        // Delete associated question paper file from disk if present
        if ($assessment->questionPaper && $assessment->questionPaper->file_path) {
            if (Storage::disk('local')->exists($assessment->questionPaper->file_path)) {
                Storage::disk('local')->delete($assessment->questionPaper->file_path);
            }
        }

        $assessmentId = $assessment->id;
        $meta = [
            'title' => $assessment->title,
            'course_id' => $assessment->course_id,
            'type' => $assessment->type,
        ];

        $assessment->delete();

        // Audit log assessment deletion event
        $this->auditLogService->log(
            'ASSESSMENT_DELETED',
            'Assessment',
            $assessmentId,
            $meta,
            $request->user()
        );

        return response()->json([
            'message' => 'Assessment deleted successfully',
        ]);
    }

    /**
     * Display assessment history for the authenticated faculty with filters.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Assessment::whereHas('course', function ($q) use ($user, $request) {
            $q->where('user_id', $user->id);
            if ($request->filled('academic_year')) {
                $q->where('academic_year', $request->academic_year);
            }
        })
            ->with(['course', 'questionPaper'])
            ->withCount('questions');

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('course', function ($cq) use ($search) {
                        $cq->where('course_code', 'like', "%{$search}%")
                            ->orWhere('course_name', 'like', "%{$search}%");
                    });
            });
        }

        $history = $query->latest('assessment_date')->latest()->get();

        return response()->json([
            'data' => $history,
        ]);
    }
}

