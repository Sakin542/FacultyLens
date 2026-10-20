<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAlert;
use App\Models\AdminIncident;
use App\Models\DataQualityFinding;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin command-palette search. Matches names, codes and titles only: document text, answers, student records and
 * settings values are never searched or returned.
 */
class AdminSearchController extends Controller
{
    protected const PER_GROUP = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $term = trim($validated['q']);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $groups = [];

        $groups['Users'] = User::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like))->orderBy('name')->limit(self::PER_GROUP)->get()
            ->map(fn (User $u) => ['id' => $u->id, 'title' => $u->name, 'subtitle' => $u->email.' · '.($u->role ?? 'FACULTY').($u->isActive() ? '' : ' · suspended'), 'url' => "/admin/users/{$u->id}"]);

        $groups['Courses'] = DB::table('courses')->join('users', 'users.id', '=', 'courses.user_id')
            ->where(fn ($q) => $q->where('courses.course_code', 'like', $like)->orWhere('courses.course_name', 'like', $like))
            ->limit(self::PER_GROUP)->get(['courses.id', 'courses.course_code', 'courses.course_name', 'courses.semester', 'courses.academic_year', 'users.name as owner'])
            ->map(fn ($c) => ['id' => $c->id, 'title' => "{$c->course_code} — {$c->course_name}", 'subtitle' => "{$c->semester} {$c->academic_year} · {$c->owner}", 'url' => '/admin/institution?search='.rawurlencode($c->course_code)]);

        $groups['Programs'] = DB::table('programs')->where(fn ($q) => $q->where('code', 'like', $like)->orWhere('name', 'like', $like))->limit(self::PER_GROUP)->get(['id', 'code', 'name', 'department'])
            ->map(fn ($p) => ['id' => $p->id, 'title' => "{$p->code} — {$p->name}", 'subtitle' => (string) $p->department, 'url' => '/admin/institution']);

        $groups['Assessments'] = DB::table('assessments')->join('courses', 'courses.id', '=', 'assessments.course_id')->where('assessments.title', 'like', $like)
            ->limit(self::PER_GROUP)->get(['assessments.id', 'assessments.title', 'courses.course_code'])
            ->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'subtitle' => $a->course_code, 'url' => '/admin/data-quality']);

        $incidentId = preg_match('/^inc-?0*(\d+)$/i', $term, $m) ? (int) $m[1] : null;
        $groups['Incidents'] = AdminIncident::query()->where(fn ($q) => $q->where('title', 'like', $like)->when($incidentId, fn ($w) => $w->orWhere('id', $incidentId)))->latest('opened_at')->limit(self::PER_GROUP)->get()
            ->map(fn (AdminIncident $i) => ['id' => $i->id, 'title' => sprintf('INC-%04d %s', $i->id, $i->title), 'subtitle' => "{$i->status} · {$i->severity}", 'url' => "/admin/incidents/{$i->id}"]);

        $groups['Alerts'] = AdminAlert::query()->where('title', 'like', $like)->latest('last_seen_at')->limit(self::PER_GROUP)->get()
            ->map(fn (AdminAlert $a) => ['id' => $a->id, 'title' => $a->title, 'subtitle' => "{$a->status} · {$a->severity}", 'url' => '/admin/alerts']);

        $groups['Data quality'] = DataQualityFinding::query()->where('entity_label', 'like', $like)->whereIn('status', ['open', 'acknowledged'])->limit(self::PER_GROUP)->get()
            ->map(fn (DataQualityFinding $f) => ['id' => $f->id, 'title' => (string) $f->entity_label, 'subtitle' => str_replace('_', ' ', $f->check), 'url' => '/admin/data-quality']);

        $groups['Audit actions'] = DB::table('audit_logs')->where('action', 'like', '%'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $term)).'%')
            ->select('action', DB::raw('COUNT(*) as n'))->groupBy('action')->orderByDesc('n')->limit(self::PER_GROUP)->get()
            ->map(fn ($a) => ['id' => $a->action, 'title' => $a->action, 'subtitle' => "{$a->n} event(s)", 'url' => '/admin/audit?action='.rawurlencode($a->action)]);

        return response()->json([
            'status' => 'success',
            'data' => collect($groups)->filter(fn ($items) => $items->isNotEmpty())->map(fn ($items, $label) => ['group' => $label, 'items' => $items->values()])->values(),
        ]);
    }
}
