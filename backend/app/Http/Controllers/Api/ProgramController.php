<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\ProgramOutcome;
use App\Services\AuditLogService;
use App\Services\CoPoMappingValidatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STEP 31: Programs and Program Outcomes (PO). Faculty-scoped (created_by) unless admin.
 * PO wording is institution-configured; nothing is hardcoded.
 */
class ProgramController extends Controller
{
    public function __construct(protected AuditLogService $auditLogService) {}

    /** GET /api/programs */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $programs = Program::with('outcomes')->withCount('courses')
            ->when(!$user->isAdmin(), fn ($q) => $q->where('created_by', $user->id))
            ->orderBy('code')->get();

        return response()->json(['status' => 'success', 'data' => $programs]);
    }

    /** POST /api/programs */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('programs')->where('created_by', $request->user()->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(Program::STATUSES)],
        ]);
        $program = Program::create($data + ['created_by' => $request->user()->id, 'status' => $data['status'] ?? Program::STATUS_ACTIVE]);
        $this->auditLogService->log('PROGRAM_CREATED', $program, $program->id, ['code' => $program->code], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Program created.', 'data' => $program->load('outcomes')], 201);
    }

    /** GET /api/programs/{program} */
    public function show(Request $request, Program $program): JsonResponse
    {
        if (!$this->owns($request, $program)) {
            return $this->forbidden();
        }
        return response()->json(['status' => 'success', 'data' => $program->load('outcomes')->loadCount('courses')]);
    }

    /** PUT /api/programs/{program} */
    public function update(Request $request, Program $program): JsonResponse
    {
        if (!$this->owns($request, $program)) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('programs')->where('created_by', $program->created_by)->ignore($program->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(Program::STATUSES)],
        ]);
        $program->update($data);
        foreach ($program->courses()->pluck('id') as $courseId) {
            CoPoMappingValidatorService::invalidateCache((int) $courseId);
        }
        $this->auditLogService->log('PROGRAM_UPDATED', $program, $program->id, ['changed' => array_keys($data)], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Program updated.', 'data' => $program->fresh('outcomes')]);
    }

    /** DELETE /api/programs/{program} */
    public function destroy(Request $request, Program $program): JsonResponse
    {
        if (!$this->owns($request, $program)) {
            return $this->forbidden();
        }
        if ($program->courses()->exists()) {
            return response()->json(['status' => 'error', 'message' => 'This program is linked to courses. Unlink the courses before deleting it.'], 422);
        }
        $this->auditLogService->log('PROGRAM_DELETED', $program, $program->id, ['code' => $program->code], $request->user());
        $program->delete();

        return response()->json(['status' => 'success', 'message' => 'Program deleted.']);
    }

    // ------------------------------------------------------------- outcomes

    /** GET /api/programs/{program}/outcomes */
    public function outcomes(Request $request, Program $program): JsonResponse
    {
        if (!$this->owns($request, $program)) {
            return $this->forbidden();
        }
        return response()->json(['status' => 'success', 'data' => $program->outcomes()->get()]);
    }

    /** POST /api/programs/{program}/outcomes */
    public function storeOutcome(Request $request, Program $program): JsonResponse
    {
        if (!$this->owns($request, $program)) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('program_outcomes')->where('program_id', $program->id)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:999'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED'])],
        ]);
        $po = $program->outcomes()->create($data + [
            'sort_order' => $data['sort_order'] ?? (($program->outcomes()->max('sort_order') ?? 0) + 1),
            'status' => $data['status'] ?? 'ACTIVE',
        ]);
        $this->invalidateProgram($program);
        $this->auditLogService->log('PROGRAM_OUTCOME_CREATED', $po, $po->id, ['program_id' => $program->id, 'code' => $po->code], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Program outcome created.', 'data' => $po], 201);
    }

    /** PUT /api/program-outcomes/{programOutcome} */
    public function updateOutcome(Request $request, ProgramOutcome $programOutcome): JsonResponse
    {
        $program = $programOutcome->program;
        if (!$program || !$this->owns($request, $program)) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('program_outcomes')->where('program_id', $program->id)->ignore($programOutcome->id)],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:999'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'ARCHIVED'])],
        ]);
        $programOutcome->update($data);
        $this->invalidateProgram($program);
        $this->auditLogService->log('PROGRAM_OUTCOME_UPDATED', $programOutcome, $programOutcome->id, ['changed' => array_keys($data)], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Program outcome updated.', 'data' => $programOutcome->fresh()]);
    }

    /** DELETE /api/program-outcomes/{programOutcome} */
    public function destroyOutcome(Request $request, ProgramOutcome $programOutcome): JsonResponse
    {
        $program = $programOutcome->program;
        if (!$program || !$this->owns($request, $program)) {
            return $this->forbidden();
        }
        $this->auditLogService->log('PROGRAM_OUTCOME_DELETED', $programOutcome, $programOutcome->id, ['code' => $programOutcome->code], $request->user());
        $programOutcome->delete();
        $this->invalidateProgram($program);

        return response()->json(['status' => 'success', 'message' => 'Program outcome deleted.']);
    }

    protected function invalidateProgram(Program $program): void
    {
        foreach ($program->courses()->pluck('id') as $courseId) {
            CoPoMappingValidatorService::invalidateCache((int) $courseId);
        }
    }

    protected function owns(Request $request, Program $program): bool
    {
        return $request->user()->isAdmin() || $program->created_by === $request->user()->id;
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Unauthorized access to this program.'], 403);
    }
}
