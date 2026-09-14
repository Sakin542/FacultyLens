<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\FacultyLensMail;
use App\Models\EmailDelivery;
use App\Services\Email\EmailContentResolver;
use App\Services\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Operator / developer surface for the e-mail system. Every endpoint is authenticated; none of them ever returns or
 * accepts SMTP configuration — the mailer is whatever the environment configured.
 *
 *   GET  /api/email/status            coarse, secret-free readiness (is e-mail on, capture or live transport, queue)
 *   POST /api/email/test              queue one test message to the caller's own address (admins: any address)
 *   GET  /api/email/deliveries[/{id}] the caller's own delivery log (masked recipients)
 *   GET  /api/email/preview/{template} rendered HTML/plain text with fake data (non-production only, never sends)
 */
class EmailController extends Controller
{
    public function __construct(protected EmailService $emails, protected EmailContentResolver $content) {}

    public function status(Request $request): JsonResponse
    {
        $mailer = (string) config('mail.default', 'log');
        $capture = in_array($mailer, ['log', 'array', 'mailpit'], true);

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => $this->emails->enabled(),
                'configured' => $mailer !== 'log' && $mailer !== 'array' && (string) config('mail.from.address') !== '',
                // true when messages are captured locally (log / array / Mailpit) instead of leaving the system
                'capture_mode' => $capture,
                'queue_connection' => (string) config('email.queue.connection') ?: (string) config('queue.default'),
                'queue' => (string) config('email.queue.name', 'emails'),
                'horizon' => class_exists(\Laravel\Horizon\Horizon::class),
                'test_endpoint_enabled' => (bool) config('email.test_endpoint_enabled'),
                'preview_enabled' => (bool) config('email.preview_enabled'),
            ],
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        if (!config('email.test_endpoint_enabled')) {
            return response()->json(['status' => 'error', 'message' => 'The e-mail test endpoint is disabled in this environment.'], 403);
        }
        $user = $request->user();
        $validated = $request->validate([
            'recipient' => ['nullable', 'string', 'email:rfc', 'max:190'],
            'sync' => ['nullable', 'boolean'],
        ]);
        $recipient = strtolower(trim((string) ($validated['recipient'] ?? $user->email)));

        // Faculty may only test against their own verified account address; admins may target any address.
        if (!$user->isAdmin() && $recipient !== strtolower((string) $user->email)) {
            return response()->json(['status' => 'error', 'message' => 'You can only send a test e-mail to your own account address.'], 403);
        }

        try {
            $delivery = $this->emails->sendTestEmail($user, $recipient, $request->boolean('sync') && $user->isAdmin());
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $delivery->status === EmailDelivery::SENT ? 'Test e-mail sent.' : 'Test e-mail queued.',
            'data' => $delivery->toApi(),
        ], 202);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(EmailDelivery::STATUSES)],
            'type' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = EmailDelivery::query()->forUser($request->user())->orderByDesc('id');
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['type'])) {
            $query->where('type', strtoupper($validated['type']));
        }
        $page = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map(fn (EmailDelivery $d) => $d->toApi())->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function delivery(Request $request, int $delivery): JsonResponse
    {
        $row = EmailDelivery::query()->forUser($request->user())->whereKey($delivery)->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Not found'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $row->toApi()]);
    }

    public function preview(Request $request, string $template): Response|JsonResponse
    {
        if (!config('email.preview_enabled')) {
            throw new HttpException(404);
        }
        if (!preg_match('/^[a-z0-9\-]{1,64}$/', $template) || !in_array($template, $this->content->templates(), true)) {
            return response()->json(['status' => 'error', 'message' => 'Unknown template.', 'templates' => $this->content->templates()], 404);
        }
        $context = $this->content->previewContext($template);
        // browsers cannot resolve cid: references — inline the emblem for the preview only
        $context['logo_src'] = FacultyLensMail::logoDataUri() ?? '';
        $mailable = new FacultyLensMail($template, $this->content->subjectFor((string) ($context['type'] ?? 'SYSTEM_ALERT')), $context, null, (string) ($context['type'] ?? null));

        if ($request->query('format') === 'text') {
            return response($mailable->renderText(), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
        }
        if ($request->query('format') === 'json') {
            return response()->json(['status' => 'success', 'data' => ['template' => $template, 'subject' => $mailable->subjectLine, 'html' => $mailable->renderHtml(), 'text' => $mailable->renderText()]]);
        }

        return response($mailable->renderHtml(), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }
}
