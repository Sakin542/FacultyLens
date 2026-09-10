<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentUploadRequest;
use App\Jobs\GenerateDocumentEmbeddingsJob;
use App\Jobs\ProcessDocumentJob;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Services\AuditLogService;
use App\Services\DocumentTextExtractor;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    protected AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    /**
     * Display a listing of documents for the authenticated faculty user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // STEP 34: own uploads plus documents of courses the user may view (authorization in SQL).
        $query = DocumentProcessing::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereIn('course_id', Course::query()->accessibleBy($user)->select('courses.id'));
        });

        if ($request->filled('course_id')) {
            $courseId = $request->input('course_id');
            $course = Course::find($courseId);
            if (!$course || !$user->can('view', $course)) {
                return response()->json([
                    'message' => 'Unauthorized access or course not found.',
                ], 403);
            }
            $query->where('course_id', $courseId);
        }

        if ($request->filled('assessment_id')) {
            $query->where('assessment_id', $request->input('assessment_id'));
        }

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->input('document_type'));
        }

        $documents = $query->with(['course:id,course_name,course_code', 'assessment:id,title,type'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $documents,
        ]);
    }

    /**
     * Store and process a newly uploaded document.
     * Text extraction is handled asynchronously via ProcessDocumentJob.
     */
    public function store(DocumentUploadRequest $request): JsonResponse
    {
        $user = $request->user();

        // 1. Verify course access (owner or collaborator with upload permission)
        $course = Course::find($request->course_id);
        if (!$course || !app(\App\Services\CourseAccessService::class)->can($user, $course, 'upload_documents')) {
            return response()->json([
                'message' => 'Unauthorized: Course does not belong to you.',
            ], 403);
        }

        // 2. Verify assessment ownership if assessment_id is provided
        if ($request->filled('assessment_id')) {
            $assessment = Assessment::where('id', $request->assessment_id)
                ->where('course_id', $course->id)
                ->first();
            if (!$assessment) {
                return response()->json([
                    'message' => 'Unauthorized: Assessment does not belong to this course.',
                ], 403);
            }
        }

        $file             = $request->file('file');
        $originalFileName = $file->getClientOriginalName();
        $extension        = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $mimeType         = $file->getClientMimeType() ?: $file->getMimeType();
        $fileSize         = $file->getSize();

        // 3. Store file securely
        $storeDirectory = "documents/user_{$user->id}/course_{$course->id}";
        $storedFilePath = $file->store($storeDirectory, 'local');
        $storedFileName = basename($storedFilePath);

        // 4. Create document record with pending state
        $document = DocumentProcessing::create([
            'user_id'            => $user->id,
            'course_id'          => $course->id,
            'assessment_id'      => $request->assessment_id,
            'document_type'      => $request->document_type,
            'original_file_name' => $originalFileName,
            'stored_file_name'   => $storedFileName,
            'file_path'          => $storedFilePath,
            'mime_type'          => $mimeType,
            'file_size'          => $fileSize,
            'processing_status'  => 'processing',
        ]);

        // 5. Dispatch asynchronous text extraction job
        ProcessDocumentJob::dispatch($document);
        $document->refresh();

        // 6. Audit log document upload event
        $this->auditLogService->log(
            'DOCUMENT_UPLOADED',
            $document,
            $document->id,
            [
                'document_type'      => $document->document_type,
                'original_file_name' => $document->original_file_name,
                'file_size'          => $document->file_size,
                'processing_status'  => $document->processing_status,
                'course_id'          => $course->id,
            ],
            $user
        );

        $document->load(['course:id,course_name,course_code', 'assessment:id,title,type']);

        return response()->json([
            'data'    => $document,
            'message' => $document->processing_status === 'completed'
                ? 'Document uploaded and processed successfully.'
                : 'Document uploaded successfully. Text extraction is processing.',
        ], 201);
    }

    /**
     * Display details and text extraction of the specified document.
     */
    public function show(Request $request, DocumentProcessing $document): JsonResponse
    {
        if (!$request->user()->can('view', $document)) {
            return response()->json([
                'message' => 'Unauthorized access to document.',
            ], 403);
        }

        $document->load(['course:id,course_name,course_code', 'assessment:id,title,type']);

        return response()->json([
            'data' => $document,
        ]);
    }

    /**
     * Reprocess text extraction for an existing document.
     */
    public function reprocess(Request $request, DocumentProcessing $document, DocumentTextExtractor $extractor): JsonResponse
    {
        if (!$request->user()->can('reprocess', $document)) {
            return response()->json([
                'message' => 'Unauthorized access to document.',
            ], 403);
        }

        if (!Storage::disk('local')->exists($document->file_path)) {
            return response()->json([
                'message' => 'Source file no longer exists on disk.',
            ], 404);
        }

        $extension = pathinfo($document->original_file_name, PATHINFO_EXTENSION);
        if (!$extension) {
            $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        }

        try {
            $absolutePath = Storage::disk('local')->path($document->file_path);
            $extractionResult = $extractor->extract($absolutePath, $extension);

            $document->update([
                'extracted_text' => $extractionResult['raw_text'],
                'cleaned_text' => $extractionResult['cleaned_text'],
                'processing_status' => 'completed',
                'processing_error' => null,
                'processed_at' => now(),
                'indexing_status' => DocumentProcessing::INDEX_STALE,
            ]);

            // STEP 32: re-index for chat (skips embedding if the text is unchanged).
            try {
                GenerateDocumentEmbeddingsJob::dispatch($document);
            } catch (\Throwable $e) {
                // Indexing failures are tracked on indexing_status; extraction succeeded regardless.
            }
        } catch (Exception $e) {
            $document->update([
                'processing_status' => 'failed',
                'processing_error' => $e->getMessage(),
                'processed_at' => now(),
            ]);
        }

        return response()->json([
            'data' => $document,
            'message' => $document->processing_status === 'completed'
                ? 'Document reprocessed successfully.'
                : 'Document reprocessing failed.',
        ]);
    }

    /**
     * Securely download the original document file.
     */
    public function download(Request $request, DocumentProcessing $document): StreamedResponse|JsonResponse
    {
        if (!$request->user()->can('download', $document)) {
            return response()->json([
                'message' => 'Unauthorized access to document.',
            ], 403);
        }

        if (!Storage::disk('local')->exists($document->file_path)) {
            return response()->json([
                'message' => 'Document file not found on server.',
            ], 404);
        }

        return Storage::disk('local')->download($document->file_path, $document->original_file_name);
    }

    /**
     * Remove the document from database and delete file from storage.
     */
    public function destroy(Request $request, DocumentProcessing $document): JsonResponse
    {
        if (!$request->user()->can('delete', $document)) {
            return response()->json([
                'message' => 'Unauthorized access to document.',
            ], 403);
        }

        if (Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        $docId = $document->id;
        $meta = [
            'document_type' => $document->document_type,
            'original_file_name' => $document->original_file_name,
            'course_id' => $document->course_id,
        ];

        $document->delete();

        // Audit log document deletion event
        $this->auditLogService->log(
            'DOCUMENT_DELETED',
            'DocumentProcessing',
            $docId,
            $meta,
            $request->user()
        );

        return response()->json([
            'message' => 'Document deleted successfully.',
        ]);
    }
}

