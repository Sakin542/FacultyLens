<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicChatSession;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Global search across the records the caller can access.
 * GET /api/search?q=&types[]=courses&limit=
 */
class SearchController extends Controller
{
    public const TYPES = ['courses', 'assessments', 'documents', 'students', 'chat_sessions'];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(self::TYPES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $user = $request->user();
        $term = '%'.addcslashes(trim($validated['q']), '%_\\').'%';
        $limit = (int) ($validated['limit'] ?? 5);
        $types = $validated['types'] ?? self::TYPES;
        $courseIds = Course::query()->accessibleBy($user)->select('courses.id');

        $results = [];

        if (in_array('courses', $types, true)) {
            $results['courses'] = Course::query()->accessibleBy($user)
                ->where(fn ($q) => $q->where('course_name', 'like', $term)->orWhere('course_code', 'like', $term))
                ->latest()->limit($limit)
                ->get(['courses.id', 'course_code', 'course_name', 'semester', 'academic_year'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => trim("{$c->course_code} {$c->course_name}"),
                    'subtitle' => trim("{$c->semester} {$c->academic_year}") ?: null,
                ])->values();
        }

        if (in_array('assessments', $types, true)) {
            $results['assessments'] = Assessment::query()->whereIn('course_id', $courseIds)
                ->where('title', 'like', $term)
                ->with('course:id,course_code')
                ->latest()->limit($limit)
                ->get(['id', 'course_id', 'title', 'type', 'status'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'course_id' => $a->course_id,
                    'title' => $a->title,
                    'subtitle' => trim(($a->course?->course_code ?? '').' '.$a->type) ?: null,
                    'status' => $a->status,
                ])->values();
        }

        if (in_array('documents', $types, true)) {
            $results['documents'] = DocumentProcessing::query()
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('course_id', $courseIds))
                ->where('original_file_name', 'like', $term)
                ->latest()->limit($limit)
                ->get(['id', 'course_id', 'original_file_name', 'document_type', 'processing_status'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'course_id' => $d->course_id,
                    'title' => $d->original_file_name,
                    'subtitle' => $d->document_type,
                    'status' => $d->processing_status,
                ])->values();
        }

        if (in_array('students', $types, true)) {
            $results['students'] = Student::query()->where('created_by', $user->id)
                ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('student_identifier', 'like', $term))
                ->orderBy('student_identifier')->limit($limit)
                ->get(['id', 'student_identifier', 'name', 'section'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'title' => $s->name,
                    'subtitle' => $s->student_identifier,
                ])->values();
        }

        if (in_array('chat_sessions', $types, true)) {
            $results['chat_sessions'] = AcademicChatSession::query()->where('user_id', $user->id)
                ->where('title', 'like', $term)
                ->orderByDesc('updated_at')->limit($limit)
                ->get(['id', 'title', 'scope_type', 'course_id'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'course_id' => $s->course_id,
                    'title' => $s->title,
                    'subtitle' => $s->scope_type,
                ])->values();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Search completed.',
            'data' => [
                'query' => trim($validated['q']),
                'results' => $results,
                'total' => collect($results)->sum(fn ($r) => $r->count()),
            ],
        ]);
    }
}
