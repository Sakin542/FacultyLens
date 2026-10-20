<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Verifies a Google Identity Services ID token (the `credential` from the "Sign in with Google" button) with
 * Google's tokeninfo endpoint, then checks audience, issuer, expiry, verified e-mail and the optional domain list.
 */
class GoogleIdTokenVerifier
{
    public function configured(): bool
    {
        return trim((string) config('admin.google.client_id')) !== '';
    }

    /**
     * @return array{sub: string, email: string, name: string}
     *
     * @throws RuntimeException with a user-safe message
     */
    public function verify(string $idToken): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('Google sign-in is not configured.');
        }

        try {
            $response = Http::connectTimeout(5)->timeout(8)->get((string) config('admin.google.tokeninfo_url'), ['id_token' => $idToken]);
        } catch (Throwable) {
            throw new RuntimeException('Google could not be reached. Please try again.');
        }
        if (!$response->ok()) {
            throw new RuntimeException('The Google sign-in token is invalid or has expired.');
        }

        $claims = (array) $response->json();
        $audience = (string) ($claims['aud'] ?? '');
        $issuer = (string) ($claims['iss'] ?? '');
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        if (!hash_equals(trim((string) config('admin.google.client_id')), $audience)) {
            throw new RuntimeException('The Google sign-in token was issued for a different application.');
        }
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new RuntimeException('The Google sign-in token has an unexpected issuer.');
        }
        if ((int) ($claims['exp'] ?? 0) < time()) {
            throw new RuntimeException('The Google sign-in token has expired.');
        }
        if ($email === '' || !$verified) {
            throw new RuntimeException('Your Google account e-mail is not verified.');
        }

        $domains = (array) config('admin.google.allowed_domains', []);
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domains !== [] && !in_array($domain, $domains, true)) {
            throw new RuntimeException('Sign in with your institutional Google account ('.implode(', ', $domains).').');
        }

        return [
            'sub' => (string) ($claims['sub'] ?? ''),
            'email' => $email,
            'name' => trim((string) ($claims['name'] ?? '')) ?: strstr($email, '@', true),
        ];
    }
}
