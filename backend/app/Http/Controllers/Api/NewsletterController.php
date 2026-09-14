<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NewsletterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Public "Faculty dispatch" endpoints. Subscribe always answers the same way (no enumeration); confirm and
 * unsubscribe are token-driven and are reached from links in the e-mails via the SPA.
 */
class NewsletterController extends Controller
{
    public const SUBSCRIBE_MESSAGE = 'Check your inbox — we sent a link to confirm your subscription.';

    public function __construct(protected NewsletterService $newsletter) {}

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate(
            ['email' => ['required', 'string', 'email:rfc,strict', 'max:190']],
            ['email.required' => 'Enter your email address.', 'email.email' => 'Enter a valid email address.', 'email.max' => 'Enter a valid email address.'],
        );

        try {
            $this->newsletter->subscribe($validated['email'], 'footer');
        } catch (Throwable $e) {
            Log::error('Newsletter subscribe failed: ' . $e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'We could not process your subscription right now. Please try again later.'], 503);
        }

        return response()->json(['status' => 'success', 'message' => self::SUBSCRIBE_MESSAGE], 202);
    }

    public function confirm(string $token): JsonResponse
    {
        if (!$this->validToken($token)) {
            return response()->json(['status' => 'error', 'message' => 'This confirmation link is not valid or has already been used.'], 404);
        }
        try {
            $this->newsletter->confirm($token);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Your subscription is confirmed. Welcome to the Faculty dispatch.']);
    }

    public function unsubscribe(string $token): JsonResponse
    {
        if (!$this->validToken($token)) {
            return response()->json(['status' => 'error', 'message' => 'This unsubscribe link is not valid.'], 404);
        }
        try {
            $this->newsletter->unsubscribe($token);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'You have been unsubscribed. You will not receive further dispatches.']);
    }

    protected function validToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{64}$/', $token);
    }
}
