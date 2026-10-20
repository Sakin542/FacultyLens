<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\StudentAnswer;
use App\Services\AiService;
use App\Services\AuditLogService;
use App\Services\CourseAccessService;
use App\Services\DocumentTextExtractor;
use App\Services\QuestionPaperParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Faculty-authored questions on an assessment: add, edit, delete, and import from the uploaded question paper
 * (preview first; nothing is saved until the faculty member confirms). Requires `edit_question` on the course.
 */
class AssessmentQuestionController extends Controller
{
    public const TYPES = ['descriptive', 'short_answer', 'mcq', 'true_false', 'problem_solving', 'other'];
    public const LEVELS = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];
    /** Paper formats read as text; scanned PDFs (no text layer) and photos of a paper go through OCR instead */
    public const TEXT_FORMATS = ['pdf', 'docx', 'txt'];
    public const IMAGE_FORMATS = ['jpg', 'jpeg', 'png', 'webp'];
    /** Fewer letters than this in a PDF means it is a scan with no text layer */
    public const SCANNED_PDF_MIN_LETTERS = 40;

    public function __construct(protected CourseAccessService $access, protected AuditLogService $audit) {}

    /** The assessment's questions in order, with their linked outcome. Anyone who can view the course may read. */
    public function index(Request $request, Assessment $assessment): JsonResponse
    {
        $assessment->loadMissing('course');
        if (!$this->access->can($request->user(), $assessment->course, 'view')) {
            return response()->json(['status' => 'error', 'message' => 'You cannot view this assessment.'], 403);
        }
        $questions = $assessment->questions()->with('learningOutcome')->orderBy('question_number')->orderBy('id')->get();

        return response()->json(['status' => 'success', 'data' => $questions, 'meta' => $this->totals($assessment)]);
    }

    public function store(Request $request, Assessment $assessment): JsonResponse
    {
        if ($denied = $this->deny($request, $assessment)) {
            return $denied;
        }
        $data = $this->validated($request, $assessment);
        $question = $assessment->questions()->create($data + ['question_number' => $data['question_number'] ?? $this->nextNumber($assessment)]);
        $this->audit->log('QUESTION_CREATED', $question, $question->id, ['assessment_id' => $assessment->id, 'marks' => (float) $question->marks], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Question added.', 'data' => $question->load('learningOutcome'), 'meta' => $this->totals($assessment)], 201);
    }

    public function update(Request $request, Question $question): JsonResponse
    {
        $assessment = $question->assessment;
        if (!$assessment || ($denied = $this->deny($request, $assessment))) {
            return $denied ?? response()->json(['message' => 'Question not found.'], 404);
        }
        $data = $this->validated($request, $assessment, partial: true);
        if (isset($data['marks'])) {
            $highest = (float) StudentAnswer::query()->where('question_id', $question->id)->max('awarded_marks');
            if ($highest > (float) $data['marks'] + 0.005) {
                return response()->json(['status' => 'error', 'message' => "A student was already awarded {$highest} marks on this question; marks cannot go below that.", 'errors' => ['marks' => ['Lower than marks already awarded.']]], 422);
            }
        }
        $question->update($data);
        $this->audit->log('QUESTION_UPDATED', $question, $question->id, ['assessment_id' => $assessment->id, 'fields' => array_keys($data)], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Question updated.', 'data' => $question->fresh('learningOutcome'), 'meta' => $this->totals($assessment)]);
    }

    public function destroy(Request $request, Question $question): JsonResponse
    {
        $assessment = $question->assessment;
        if (!$assessment || ($denied = $this->deny($request, $assessment))) {
            return $denied ?? response()->json(['message' => 'Question not found.'], 404);
        }
        if (StudentAnswer::query()->where('question_id', $question->id)->exists()) {
            return response()->json(['status' => 'error', 'code' => 'QUESTION_HAS_ANSWERS', 'message' => 'Students have already answered this question, so it cannot be deleted. Edit it instead.'], 409);
        }
        $id = $question->id;
        $question->delete();
        $this->audit->log('QUESTION_DELETED', 'Question', $id, ['assessment_id' => $assessment->id], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Question deleted.', 'meta' => $this->totals($assessment)]);
    }

    /** Read the uploaded question paper and return the questions found in it. Saves nothing. */
    public function extract(Request $request, Assessment $assessment, DocumentTextExtractor $extractor, QuestionPaperParser $parser, AiService $ai): JsonResponse
    {
        if ($denied = $this->deny($request, $assessment)) {
            return $denied;
        }
        $paper = $assessment->questionPaper;
        if (!$paper || !$paper->file_path || !Storage::disk('local')->exists($paper->file_path)) {
            return response()->json(['status' => 'error', 'message' => 'Upload the question paper first.'], 422);
        }
        $extension = strtolower((string) $paper->file_type);
        $isImage = in_array($extension, self::IMAGE_FORMATS, true);
        if (!$isImage && !in_array($extension, self::TEXT_FORMATS, true)) {
            return response()->json(['status' => 'error', 'message' => "Questions can be read from PDF, DOCX or TXT papers, or a photo or scan of the paper; this paper is .{$extension}. Add the questions by hand or upload one of those formats."], 422);
        }
        $path = Storage::disk('local')->path($paper->file_path);

        $text = '';
        if (!$isImage) {
            try {
                $result = $extractor->extract($path, $extension);
                $text = (string) ($result['raw_text'] ?? '') ?: (string) ($result['cleaned_text'] ?? '');
            } catch (Throwable $e) {
                if ($extension !== 'pdf') {
                    return response()->json(['status' => 'error', 'message' => 'The question paper could not be read.'], 422);
                }
            }
        }

        // Photos, and PDFs without a text layer (scans), are read with OCR: the hosted vision model when the
        // administrator selected the hosted provider, else the local OCR model
        $ocr = null;
        if ($isImage || ($extension === 'pdf' && preg_match_all('/\pL/u', $text) < self::SCANNED_PDF_MIN_LETTERS)) {
            try {
                $read = $ai->ocr($path, (string) ($paper->file_name ?: basename($path)));
                $text = (string) ($read['text'] ?? '');
                $ocr = ['model' => $read['model'] ?? null, 'provider' => $read['provider'] ?? null];
            } catch (Throwable $e) {
                return response()->json(['status' => 'error', 'message' => 'This looks like a scanned paper, and it could not be read with OCR right now. Try again later or add the questions by hand.'], 422);
            }
        }

        $parsed = $parser->parse($text, $assessment->total_marks !== null ? (float) $assessment->total_marks : null);
        if (trim($text) === '') {
            $parsed['warnings'] = ['No text was found in the question paper. Add the questions by hand.'];
        } elseif ($ocr) {
            array_unshift($parsed['warnings'], 'This paper was read from an image with OCR. Check every question and its marks against the paper before saving.');
        }

        return response()->json(['status' => 'success', 'data' => $parsed + ['file_name' => $paper->file_name, 'existing_questions' => $assessment->questions()->count(), 'ocr' => $ocr]]);
    }

    /** Save several reviewed questions at once (e.g. from the paper preview); numbering continues after existing ones. */
    public function bulkStore(Request $request, Assessment $assessment): JsonResponse
    {
        if ($denied = $this->deny($request, $assessment)) {
            return $denied;
        }
        $request->validate(['questions' => ['required', 'array', 'min:1', 'max:' . QuestionPaperParser::MAX_QUESTIONS]]);
        $rows = [];
        foreach (array_values($request->input('questions')) as $i => $row) {
            $sub = Request::create('/', 'POST', is_array($row) ? $row : []);
            $sub->setUserResolver($request->getUserResolver());
            try {
                $rows[] = $this->validated($sub, $assessment);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['status' => 'error', 'message' => 'Question ' . ($i + 1) . ': ' . collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
            }
        }
        $created = DB::transaction(function () use ($assessment, $rows) {
            $next = $this->nextNumber($assessment);
            $ids = [];
            foreach ($rows as $data) {
                unset($data['question_number']);
                $ids[] = $assessment->questions()->create($data + ['question_number' => $next++])->id;
            }

            return $ids;
        });
        $this->audit->log('QUESTIONS_IMPORTED', $assessment, $assessment->id, ['count' => count($created), 'source' => (string) $request->input('source', 'manual')], $request->user());

        return response()->json(['status' => 'success', 'message' => count($created) . ' question(s) added.', 'data' => ['ids' => $created], 'meta' => $this->totals($assessment)], 201);
    }

    // ------------------------------------------------------------------ helpers

    protected function deny(Request $request, Assessment $assessment): ?JsonResponse
    {
        $assessment->loadMissing('course');

        return $this->access->can($request->user(), $assessment->course, 'edit_question')
            ? null
            : response()->json(['status' => 'error', 'message' => 'You cannot edit the questions of this assessment.'], 403);
    }

    protected function validated(Request $request, Assessment $assessment, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'question_text' => [$req, 'string', 'min:3', 'max:10000'],
            'marks' => [$req, 'numeric', 'min:0', 'max:1000'],
            'question_number' => ['nullable', 'integer', 'min:1', 'max:999'],
            'question_type' => ['sometimes', 'nullable', Rule::in(self::TYPES)],
            'difficulty_level' => ['sometimes', 'nullable', Rule::in(self::DIFFICULTIES)],
            'cognitive_level' => ['sometimes', 'nullable', Rule::in(self::LEVELS)],
            'learning_outcome_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists(LearningOutcome::class, 'id')->where('course_id', $assessment->course_id)],
            'expected_answer' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ], [
            'learning_outcome_id.exists' => 'Choose a learning outcome of this course.',
        ]);
        if (array_key_exists('question_text', $data)) {
            $data['question_text'] = trim($data['question_text']);
        }
        // Columns have defaults; a null from the form means "use the default"
        foreach (['question_type' => 'descriptive', 'difficulty_level' => 'medium', 'cognitive_level' => 'Understand'] as $k => $default) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = $default;
            }
        }

        return $data;
    }

    protected function nextNumber(Assessment $assessment): int
    {
        return (int) $assessment->questions()->max('question_number') + 1;
    }

    protected function totals(Assessment $assessment): array
    {
        $sum = (float) $assessment->questions()->sum('marks');

        return ['questions' => $assessment->questions()->count(), 'marks_sum' => round($sum, 2), 'total_marks' => $assessment->total_marks !== null ? (float) $assessment->total_marks : null];
    }
}
