<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentAnswerRequest;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Services\StudentSubmissionService;
use App\Services\SubmissionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * STEP 26: Individual student answers (text and/or private file).
 */
class StudentAnswerController extends Controller
{
    public function __construct(protected StudentSubmissionService $service) {}

    /**
     * POST /api/submissions/{submission}/answers
     */
    public function store(StudentAnswerRequest $request, StudentSubmission $submission): JsonResponse
    {
        if (!$request->user()->can('update', $submission)) {
            return $this->forbidden('Unauthorized access to this submission.');
        }

        try {
            $answer = $this->service->addAnswer($submission, $request->user(), $request->validated(), $request->file('file'));
        } catch (SubmissionException $e) {
            return $this->error($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Answer saved.',
            'data' => StudentSubmissionController::presentAnswer($answer),
        ], 201);
    }

    /**
     * PUT /api/student-answers/{answer}
     */
    public function update(StudentAnswerRequest $request, StudentAnswer $answer): JsonResponse
    {
        if (!$request->user()->can('update', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        try {
            $updated = $this->service->updateAnswer($answer, $request->user(), $request->validated(), $request->file('file'));
        } catch (SubmissionException $e) {
            return $this->error($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Answer updated.',
            'data' => StudentSubmissionController::presentAnswer($updated),
        ]);
    }

    /**
     * DELETE /api/student-answers/{answer}
     */
    public function destroy(Request $request, StudentAnswer $answer): JsonResponse
    {
        if (!$request->user()->can('delete', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        $this->service->deleteAnswer($answer, $request->user());

        return response()->json(['status' => 'success', 'message' => 'Answer deleted.']);
    }

    /**
     * GET /api/student-answers/{answer}/download — private file, ownership-checked.
     */
    public function download(Request $request, StudentAnswer $answer): StreamedResponse|JsonResponse
    {
        if (!$request->user()->can('view', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        if (!$this->service->fileExists($answer)) {
            return response()->json(['status' => 'error', 'message' => 'No answer file is available.'], 404);
        }

        return Storage::disk(StudentSubmissionService::DISK)->download(
            $answer->answer_file_path,
            $answer->answer_file_name ?: 'answer',
            ['Content-Type' => $answer->answer_file_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }

    protected function error(SubmissionException $e): JsonResponse
    {
        $body = ['status' => 'error', 'message' => $e->getMessage()];
        if ($e->getDetails()) {
            $body['errors'] = $e->getDetails();
        }

        return response()->json($body, $e->getStatus());
    }
}
