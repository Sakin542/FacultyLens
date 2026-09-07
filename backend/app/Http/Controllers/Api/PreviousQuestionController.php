<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviousQuestionRequest;
use App\Models\Course;
use App\Models\PreviousQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreviousQuestionController extends Controller
{
    /**
     * Display a paginated listing of previous questions for a course with search & filters.
     */
    public function index(Request $request, Course $course): JsonResponse
    {
        if ($course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to course previous questions.',
            ], 403);
        }

        $query = $course->previousQuestions();

        // Search in question_text or source_assessment
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('question_text', 'like', "%{$search}%")
                    ->orWhere('source_assessment', 'like', "%{$search}%")
                    ->orWhere('source_year', 'like', "%{$search}%");
            });
        }

        // Filter by question_type
        if ($request->filled('question_type') && $request->question_type !== 'all') {
            $query->where('question_type', $request->question_type);
        }

        // Filter by difficulty_level
        if ($request->filled('difficulty_level') && $request->difficulty_level !== 'all') {
            $query->where('difficulty_level', $request->difficulty_level);
        }

        // Filter by cognitive_level
        if ($request->filled('cognitive_level') && $request->cognitive_level !== 'all') {
            $query->where('cognitive_level', $request->cognitive_level);
        }

        // Filter by source_year
        if ($request->filled('source_year') && $request->source_year !== 'all') {
            $query->where('source_year', $request->source_year);
        }

        // Filter by source
        if ($request->filled('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'newest');
        switch ($sortBy) {
            case 'oldest':
                $query->oldest();
                break;
            case 'marks_asc':
                $query->orderBy('marks', 'asc');
                break;
            case 'marks_desc':
                $query->orderBy('marks', 'desc');
                break;
            case 'difficulty':
                $query->orderByRaw("FIELD(difficulty_level, 'easy', 'medium', 'hard')");
                break;
            case 'newest':
            default:
                $query->latest();
                break;
        }

        $perPage = min(max((int) $request->get('per_page', 10), 1), 100);
        $paginated = $query->paginate($perPage);

        return response()->json($paginated);
    }

    /**
     * Store a newly created previous question (manual entry or file upload).
     */
    public function store(PreviousQuestionRequest $request, Course $course): JsonResponse
    {
        if ($course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to add previous question for this course.',
            ], 403);
        }

        $data = $request->validated();
        $data['user_id'] = $request->user()->id;

        // If file is provided, handle file upload
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $fileSize = $file->getSize();

            $storedPath = $file->store('previous_questions', 'local');

            $data['file_name'] = $originalName;
            $data['file_path'] = $storedPath;
            $data['file_type'] = $extension;
            $data['file_size'] = $fileSize;

            if (empty($data['question_text'])) {
                $data['question_text'] = "Uploaded previous question paper: {$originalName}";
            }
            if (empty($data['source'])) {
                $data['source'] = 'uploaded_document';
            }
        } else {
            if (empty($data['source'])) {
                $data['source'] = 'manual';
            }
        }

        $question = $course->previousQuestions()->create($data);

        return response()->json([
            'data' => $question,
            'message' => 'Previous question added successfully',
        ], 201);
    }

    /**
     * Display or download the specified previous question.
     */
    public function show(Request $request, PreviousQuestion $previousQuestion): StreamedResponse|JsonResponse
    {
        if ($previousQuestion->course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to previous question.',
            ], 403);
        }

        // If requested with download flag and has attached file
        if ($request->has('download') && $previousQuestion->file_path) {
            if (! Storage::disk('local')->exists($previousQuestion->file_path)) {
                return response()->json([
                    'message' => 'File not found on server.',
                ], 404);
            }
            return Storage::disk('local')->download($previousQuestion->file_path, $previousQuestion->file_name);
        }

        $previousQuestion->load('course');

        return response()->json([
            'data' => $previousQuestion,
        ]);
    }

    /**
     * Update the specified previous question in storage.
     */
    public function update(PreviousQuestionRequest $request, PreviousQuestion $previousQuestion): JsonResponse
    {
        if ($previousQuestion->course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to update previous question.',
            ], 403);
        }

        $data = $request->validated();

        if ($request->hasFile('file')) {
            // Delete old file if present
            if ($previousQuestion->file_path && Storage::disk('local')->exists($previousQuestion->file_path)) {
                Storage::disk('local')->delete($previousQuestion->file_path);
            }

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $fileSize = $file->getSize();

            $storedPath = $file->store('previous_questions', 'local');

            $data['file_name'] = $originalName;
            $data['file_path'] = $storedPath;
            $data['file_type'] = $extension;
            $data['file_size'] = $fileSize;
        }

        $previousQuestion->update($data);

        return response()->json([
            'data' => $previousQuestion,
            'message' => 'Previous question updated successfully',
        ]);
    }

    /**
     * Remove the specified previous question from storage.
     */
    public function destroy(Request $request, PreviousQuestion $previousQuestion): JsonResponse
    {
        if ($previousQuestion->course->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized access to delete previous question.',
            ], 403);
        }

        if ($previousQuestion->file_path && Storage::disk('local')->exists($previousQuestion->file_path)) {
            Storage::disk('local')->delete($previousQuestion->file_path);
        }

        $previousQuestion->delete();

        return response()->json([
            'message' => 'Previous question deleted successfully',
        ]);
    }
}

