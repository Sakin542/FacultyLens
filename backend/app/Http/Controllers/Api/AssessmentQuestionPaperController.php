<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionPaperRequest;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssessmentQuestionPaperController extends Controller
{
    /**
     * Download or view the assessment question paper file safely.
     */
    public function show(Request $request, Assessment $assessment): StreamedResponse|JsonResponse
    {
        if (!app(\App\Services\CourseAccessService::class)->can($request->user(), $assessment->course, 'download_documents')) {
            return response()->json([
                'message' => 'Unauthorized access to assessment question paper.',
            ], 403);
        }

        $paper = $assessment->questionPaper;

        if (! $paper || ! Storage::disk('local')->exists($paper->file_path)) {
            return response()->json([
                'message' => 'Question paper file was not found on server.',
            ], 404);
        }

        return Storage::disk('local')->download($paper->file_path, $paper->file_name);
    }

    /**
     * Upload or replace the question paper file for the given assessment.
     */
    public function store(QuestionPaperRequest $request, Assessment $assessment): JsonResponse
    {
        if (!$request->user()->can('update', $assessment)) {
            return response()->json([
                'message' => 'Unauthorized access to upload question paper for this assessment.',
            ], 403);
        }

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $fileSize = $file->getSize();

        // If an existing question paper exists, delete the old physical file
        $existingPaper = $assessment->questionPaper;
        if ($existingPaper && $existingPaper->file_path && Storage::disk('local')->exists($existingPaper->file_path)) {
            Storage::disk('local')->delete($existingPaper->file_path);
        }

        // Store file securely in storage/app/question_papers
        $storedPath = $file->store('question_papers', 'local');

        $paperData = [
            'uploaded_by' => $request->user()->id,
            'file_name' => $originalName,
            'file_path' => $storedPath,
            'file_type' => $extension,
            'file_size' => $fileSize,
        ];

        if ($existingPaper) {
            $existingPaper->update($paperData);
            $paper = $existingPaper;
        } else {
            $paper = $assessment->questionPaper()->create($paperData);
        }

        return response()->json([
            'data' => $paper,
            'message' => 'Question paper uploaded successfully',
        ], 201);
    }

    /**
     * Remove the question paper file for the given assessment.
     */
    public function destroy(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$request->user()->can('update', $assessment)) {
            return response()->json([
                'message' => 'Unauthorized access to delete question paper.',
            ], 403);
        }

        $paper = $assessment->questionPaper;

        if (! $paper) {
            return response()->json([
                'message' => 'No question paper exists for this assessment.',
            ], 404);
        }

        if (Storage::disk('local')->exists($paper->file_path)) {
            Storage::disk('local')->delete($paper->file_path);
        }

        $paper->delete();

        return response()->json([
            'message' => 'Question paper removed successfully',
        ]);
    }
}

