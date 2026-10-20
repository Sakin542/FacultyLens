<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read-only oversight of the academic structure. FacultyLens has no departments table: departments are the
 * distinct values on accounts and programs. Course and assessment content stays in the faculty modules; this
 * controller only returns metadata and counts.
 */
class AdminInstitutionController extends Controller
{
    public function overview(): JsonResponse
    {
        $users = DB::table('users')->selectRaw("TRIM(department) as department, COUNT(*) as members, SUM(CASE WHEN UPPER(COALESCE(role, 'FACULTY')) = 'DEPARTMENT_HEAD' THEN 1 ELSE 0 END) as heads")
            ->whereNotNull('department')->where('department', '!=', '')->groupBy(DB::raw('TRIM(department)'))->get();
        $courses = DB::table('courses')->join('users', 'users.id', '=', 'courses.user_id')
            ->selectRaw('TRIM(users.department) as department, COUNT(courses.id) as courses')
            ->whereNotNull('users.department')->where('users.department', '!=', '')->groupBy(DB::raw('TRIM(users.department)'))->pluck('courses', 'department');
        $programs = DB::table('programs')->selectRaw('TRIM(department) as department, COUNT(*) as n')
            ->whereNotNull('department')->where('department', '!=', '')->groupBy(DB::raw('TRIM(department)'))->pluck('n', 'department');

        $departments = [];
        foreach ($users as $row) {
            $key = mb_strtolower($row->department);
            $departments[$key] = ['name' => $row->department, 'members' => (int) $row->members, 'heads' => (int) $row->heads, 'courses' => 0, 'programs' => 0];
        }
        foreach ([['courses', $courses], ['programs', $programs]] as [$field, $map]) {
            foreach ($map as $name => $n) {
                $key = mb_strtolower((string) $name);
                $departments[$key] ??= ['name' => $name, 'members' => 0, 'heads' => 0, 'courses' => 0, 'programs' => 0];
                $departments[$key][$field] += (int) $n;
            }
        }
        $departments = collect($departments)->map(fn ($d) => $d + ['without_head' => $d['members'] > 0 && $d['heads'] === 0])->sortBy('name')->values();

        $programRows = DB::table('programs')
            ->leftJoin('courses', 'courses.program_id', '=', 'programs.id')
            ->select('programs.id', 'programs.code', 'programs.name', 'programs.department', 'programs.status', DB::raw('COUNT(DISTINCT courses.id) as courses'))
            ->groupBy('programs.id', 'programs.code', 'programs.name', 'programs.department', 'programs.status')
            ->orderBy('programs.code')->get();
        $outcomes = DB::table('program_outcomes')->select('program_id', DB::raw('COUNT(*) as n'))->groupBy('program_id')->pluck('n', 'program_id');

        $terms = DB::table('courses')->select('academic_year', 'semester', DB::raw('COUNT(*) as courses'), DB::raw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active"))
            ->groupBy('academic_year', 'semester')->orderByDesc('academic_year')->orderBy('semester')->limit(40)->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'totals' => [
                    'departments' => $departments->count(),
                    'programs' => $programRows->count(),
                    'courses' => DB::table('courses')->count(),
                    'active_courses' => DB::table('courses')->where('status', 'active')->count(),
                    'learning_outcomes' => DB::table('learning_outcomes')->count(),
                    'assessments' => DB::table('assessments')->count(),
                    'users_without_department' => DB::table('users')->where(fn ($q) => $q->whereNull('department')->orWhere('department', ''))->count(),
                ],
                'departments' => $departments,
                'programs' => $programRows->map(fn ($p) => [
                    'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'department' => $p->department, 'status' => $p->status,
                    'courses' => (int) $p->courses, 'outcomes' => (int) ($outcomes[$p->id] ?? 0),
                ]),
                'terms' => $terms->map(fn ($t) => ['academic_year' => $t->academic_year, 'semester' => $t->semester, 'courses' => (int) $t->courses, 'active' => (int) $t->active]),
            ],
        ]);
    }

    public function courses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:255'],
            'program_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'gap' => ['nullable', Rule::in(['no_outcomes', 'no_program', 'no_assessments'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DB::table('courses')
            ->join('users', 'users.id', '=', 'courses.user_id')
            ->leftJoin('programs', 'programs.id', '=', 'courses.program_id')
            ->select('courses.id', 'courses.course_code', 'courses.course_name', 'courses.semester', 'courses.academic_year', 'courses.credits', 'courses.status', 'courses.created_at',
                'users.id as owner_id', 'users.name as owner_name', 'users.department', 'programs.code as program_code')
            ->selectSub(DB::table('learning_outcomes')->selectRaw('COUNT(*)')->whereColumn('learning_outcomes.course_id', 'courses.id'), 'outcomes_count')
            ->selectSub(DB::table('assessments')->selectRaw('COUNT(*)')->whereColumn('assessments.course_id', 'courses.id'), 'assessments_count')
            ->selectSub(DB::table('course_collaborators')->selectRaw('COUNT(*)')->whereColumn('course_collaborators.course_id', 'courses.id'), 'collaborators_count')
            ->orderByDesc('courses.academic_year')->orderBy('courses.course_code');

        if ($term = trim((string) ($validated['search'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('courses.course_code', 'like', $like)->orWhere('courses.course_name', 'like', $like)->orWhere('users.name', 'like', $like));
        }
        if (!empty($validated['department'])) {
            $query->whereRaw('LOWER(TRIM(users.department)) = ?', [mb_strtolower(trim($validated['department']))]);
        }
        if (!empty($validated['program_id'])) {
            $query->where('courses.program_id', $validated['program_id']);
        }
        if (!empty($validated['status'])) {
            $query->where('courses.status', $validated['status']);
        }
        match ($validated['gap'] ?? null) {
            'no_outcomes' => $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('learning_outcomes')->whereColumn('learning_outcomes.course_id', 'courses.id')),
            'no_program' => $query->whereNull('courses.program_id'),
            'no_assessments' => $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('assessments')->whereColumn('assessments.course_id', 'courses.id')),
            default => null,
        };

        $page = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map(fn ($c) => [
                'id' => $c->id,
                'course_code' => $c->course_code,
                'course_name' => $c->course_name,
                'semester' => $c->semester,
                'academic_year' => $c->academic_year,
                'credits' => (int) $c->credits,
                'status' => $c->status,
                'owner' => ['id' => $c->owner_id, 'name' => $c->owner_name],
                'department' => $c->department,
                'program_code' => $c->program_code,
                'outcomes_count' => (int) $c->outcomes_count,
                'assessments_count' => (int) $c->assessments_count,
                'collaborators_count' => (int) $c->collaborators_count,
                'gaps' => array_values(array_filter([
                    (int) $c->outcomes_count === 0 ? 'No learning outcomes' : null,
                    $c->program_code === null ? 'No program' : null,
                    (int) $c->assessments_count === 0 ? 'No assessments' : null,
                ])),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
