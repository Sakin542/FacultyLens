<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRequest;
use App\Models\Student;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STEP 26: Faculty-scoped student identities (no student login accounts).
 */
class StudentController extends Controller
{
    public function __construct(protected AuditLogService $auditLogService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Student::where('created_by', $request->user()->id);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('student_identifier', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);
        $paginated = $query->orderBy('student_identifier')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function store(StudentRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $exists = Student::where('created_by', $user->id)
            ->whereRaw('LOWER(student_identifier) = ?', [strtolower($data['student_identifier'])])
            ->exists();
        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'A student with this identifier already exists.',
                'errors' => ['student_identifier' => ['A student with this identifier already exists.']],
            ], 422);
        }

        $student = Student::create($data + ['created_by' => $user->id]);

        $this->auditLogService->log('STUDENT_CREATED', $student, $student->id, [
            'student_identifier' => $student->student_identifier,
        ], $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Student registered.',
            'data' => $student,
        ], 201);
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        if (!$request->user()->can('view', $student)) {
            return $this->forbidden();
        }

        $student->loadCount('submissions');

        return response()->json(['status' => 'success', 'data' => $student]);
    }

    public function update(StudentRequest $request, Student $student): JsonResponse
    {
        if (!$request->user()->can('update', $student)) {
            return $this->forbidden();
        }

        $data = $request->validated();
        if (isset($data['student_identifier'])) {
            $exists = Student::where('created_by', $student->created_by)
                ->where('id', '!=', $student->id)
                ->whereRaw('LOWER(student_identifier) = ?', [strtolower($data['student_identifier'])])
                ->exists();
            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A student with this identifier already exists.',
                ], 422);
            }
        }

        $student->update($data);

        $this->auditLogService->log('STUDENT_UPDATED', $student, $student->id, [
            'changed_fields' => array_keys($data),
        ], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Student updated.', 'data' => $student->fresh()]);
    }

    public function destroy(Request $request, Student $student): JsonResponse
    {
        if (!$request->user()->can('delete', $student)) {
            return $this->forbidden();
        }

        if ($student->submissions()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This student has submissions. Delete the submissions first.',
            ], 422);
        }

        $this->auditLogService->log('STUDENT_DELETED', $student, $student->id, [
            'student_identifier' => $student->student_identifier,
        ], $request->user());

        $student->delete();

        return response()->json(['status' => 'success', 'message' => 'Student deleted.']);
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Unauthorized access to this student.'], 403);
    }
}
