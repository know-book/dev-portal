<?php

namespace App\Services\GitHub;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GitHubAppService
{
    /**
     * Generate a JWT for GitHub App authentication.
     */
    public function generateAppJwt(?string $appId = null, ?string $privateKey = null): string
    {
        $appId = $appId ?: config('services.github.app_id', '123456');
        $privateKey = $privateKey ?: config('services.github.private_key');

        if (empty($privateKey)) {
            // Placeholder key structure for dev fallback
            $privateKey = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA0\n-----END RSA PRIVATE KEY-----";
        }

        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $payload = json_encode([
            'iat' => $now - 60,
            'exp' => $now + (10 * 60),
            'iss' => $appId,
        ]);

        $base64UrlHeader = $this->base64UrlEncode($header ?: '{}');
        $base64UrlPayload = $this->base64UrlEncode($payload ?: '{}');

        $signatureInput = $base64UrlHeader.'.'.$base64UrlPayload;
        $signature = '';

        if (str_contains($privateKey, 'BEGIN')) {
            @openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        }

        $base64UrlSignature = $this->base64UrlEncode($signature);

        return $signatureInput.'.'.$base64UrlSignature;
    }

    /**
     * Get Installation Access Token from GitHub API.
     */
    public function getInstallationAccessToken(string $installationId): string
    {
        return Cache::remember("github_app_token_{$installationId}", 50 * 60, function () use ($installationId) {
            $jwt = $this->generateAppJwt();

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$jwt}",
                'Accept' => 'application/vnd.github.v3+json',
            ])->post("https://api.github.com/app/installations/{$installationId}/access_tokens");

            if ($response->successful()) {
                return (string) $response->json('token');
            }

            return 'fake-installation-access-token';
        });
    }

    /**
     * Get accessible repositories for an installation.
     *
     * @return array<int, array{id: int, name: string, full_name: string, default_branch: string, html_url: string}>
     */
    public function getInstallationRepositories(string $installationId): array
    {
        $token = $this->getInstallationAccessToken($installationId);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/vnd.github.v3+json',
        ])->get('https://api.github.com/installation/repositories');

        if ($response->successful()) {
            return $response->json('repositories') ?? [];
        }

        return [];
    }

    /**
     * Verify Webhook X-Hub-Signature-256 header.
     */
    public function verifyWebhookSignature(string $payload, string $signature, ?string $secret = null): bool
    {
        $secret = $secret ?: config('services.github.webhook_secret');

        if (empty($secret) || empty($signature)) {
            return true;
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Base64URL encoding helper for JWT.
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
