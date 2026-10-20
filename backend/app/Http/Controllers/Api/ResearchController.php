<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\PaperSummary;
use App\Services\AiService;
use App\Services\CourseAccessService;
use App\Services\DocumentTextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Research paper summaries: upload a PDF/DOCX/TXT (or paste text); only the summary is stored, never the file. */
class ResearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = PaperSummary::query()->where('user_id', $request->user()->id)->with('course:id,course_code')->latest('id')->limit(100)->get();

        return response()->json(['status' => 'success', 'data' => $items->map(fn ($s) => $s->toPayload(false))->values()]);
    }

    public function show(Request $request, PaperSummary $summary): JsonResponse
    {
        abort_unless($summary->user_id === $request->user()->id, 404);

        return response()->json(['status' => 'success', 'data' => $summary->load('course:id,course_code')->toPayload()]);
    }

    public function store(Request $request, AiService $ai, DocumentTextExtractor $extractor, CourseAccessService $access): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,docx,txt', 'required_without:text'],
            'text' => ['nullable', 'string', 'min:200', 'max:600000'],
            'title' => ['nullable', 'string', 'max:300'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
        ]);
        if (!empty($validated['course_id'])) {
            abort_unless($access->can($request->user(), Course::find($validated['course_id']), 'view'), 403, 'You do not have access to that course.');
        }

        $fileName = null;
        $text = $validated['text'] ?? '';
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();
            try {
                $text = $extractor->extract($file->getRealPath(), strtolower($file->getClientOriginalExtension()))['raw_text'];
            } catch (Throwable $e) {
                return response()->json(['status' => 'error', 'message' => 'The text could not be read from this file. If it is a scanned PDF, paste the text instead.'], 422);
            }
        }

        try {
            $summary = $ai->summarizePaper(['text' => mb_substr($text, 0, 600000), 'title' => $validated['title'] ?? null]);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'too short') ? 422 : 503;

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $status);
        }

        $record = PaperSummary::query()->create([
            'user_id' => $request->user()->id,
            'course_id' => $validated['course_id'] ?? null,
            'title' => $validated['title'] ?? ($summary['title'] ?? $fileName),
            'file_name' => $fileName,
            'word_count' => (int) ($summary['stats']['words'] ?? str_word_count($text)),
            'summary' => $summary,
        ]);

        return response()->json(['status' => 'success', 'message' => 'Summary ready.', 'data' => $record->load('course:id,course_code')->toPayload()], 201);
    }

    public function destroy(Request $request, PaperSummary $summary): JsonResponse
    {
        abort_unless($summary->user_id === $request->user()->id, 404);
        $summary->delete();

        return response()->json(['status' => 'success', 'message' => 'Summary deleted.']);
    }
}
