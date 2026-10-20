<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform-wide view of the existing e-mail and notification pipeline (email_deliveries + notifications).
 * Recipients stay masked and message bodies are never stored, so none can be shown.
 */
class AdminEmailController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90']]);
        $days = (int) $request->query('days', 30);
        $since = now()->subDays($days)->startOfDay();

        $byStatus = EmailDelivery::query()->where('created_at', '>=', $since)->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status');
        $byTemplate = EmailDelivery::query()->where('created_at', '>=', $since)
            ->select('template', DB::raw('COUNT(*) as total'), DB::raw("SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed"))
            ->groupBy('template')->orderByDesc('total')->limit(20)->get()
            ->map(fn ($r) => ['template' => $r->template, 'total' => (int) $r->total, 'failed' => (int) $r->failed]);
        $errors = EmailDelivery::query()->where('status', EmailDelivery::FAILED)->where('created_at', '>=', $since)
            ->select('error_code', DB::raw('COUNT(*) as n'))->groupBy('error_code')->orderByDesc('n')->limit(10)->get()
            ->map(fn ($r) => ['error_code' => $r->error_code ?? 'UNKNOWN', 'count' => (int) $r->n]);

        $daily = EmailDelivery::query()->where('created_at', '>=', $since)
            ->selectRaw("DATE(created_at) as d, SUM(CASE WHEN status = 'SENT' THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed")
            ->groupBy(DB::raw('DATE(created_at)'))->get()->keyBy('d');
        $trend = [];
        for ($i = 0; $i <= $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            if ($date > now()->toDateString()) {
                break;
            }
            $trend[] = ['date' => $date, 'ok' => (int) ($daily[$date]->sent ?? 0), 'failed' => (int) ($daily[$date]->failed ?? 0), 'total' => (int) ($daily[$date]->sent ?? 0) + (int) ($daily[$date]->failed ?? 0)];
        }

        $latency = EmailDelivery::query()->where('status', EmailDelivery::SENT)->where('created_at', '>=', $since)->whereNotNull('queued_at')->whereNotNull('sent_at')
            ->latest('id')->limit(500)->get(['queued_at', 'sent_at'])
            ->map(fn ($d) => max(0, $d->queued_at->diffInSeconds($d->sent_at)));

        $notifications = DB::table('notifications')->where('created_at', '>=', $since);

        return response()->json([
            'status' => 'success',
            'data' => [
                'days' => $days,
                'mailer' => config('mail.default'),
                'delivering' => !in_array(config('mail.default'), ['log', 'array'], true),
                'broadcasting' => config('broadcasting.default'),
                'by_status' => collect(EmailDelivery::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)]),
                'stuck' => EmailDelivery::query()->whereIn('status', [EmailDelivery::PENDING, EmailDelivery::PROCESSING])->where('created_at', '<', now()->subMinutes(30))->count(),
                'by_template' => $byTemplate,
                'errors' => $errors,
                'trend' => $trend,
                'latency_seconds' => $latency->isEmpty() ? null : [
                    'median' => (int) $latency->median(),
                    'p95' => (int) $latency->sort()->values()->get((int) floor(($latency->count() - 1) * 0.95)),
                    'sample' => $latency->count(),
                ],
                'notifications' => [
                    'created' => (clone $notifications)->count(),
                    'unread' => (clone $notifications)->whereNull('read_at')->whereNull('dismissed_at')->count(),
                    'by_type' => (clone $notifications)->select('type', DB::raw('COUNT(*) as n'))->groupBy('type')->orderByDesc('n')->limit(10)->get()
                        ->map(fn ($r) => ['type' => class_basename((string) $r->type), 'count' => (int) $r->n]),
                ],
                'retry_note' => 'Failed e-mails cannot be re-sent from the panel: message context (including one-time links) is intentionally not stored. The user repeats the action instead, e.g. requests a new reset link.',
            ],
        ]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(EmailDelivery::STATUSES)],
            'template' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = EmailDelivery::query()->latest('id');
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['template'])) {
            $query->where('template', $validated['template']);
        }
        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map(fn (EmailDelivery $d) => $d->toApi())->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
