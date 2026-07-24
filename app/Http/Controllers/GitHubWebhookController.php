<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGitHubWebhookJob;
use App\Services\GitHub\GitHubAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    /**
     * Handle incoming GitHub App webhooks.
     */
    public function __invoke(Request $request, GitHubAppService $githubService): JsonResponse
    {
        $signature = $request->header('X-Hub-Signature-256', '');
        $event = $request->header('X-GitHub-Event', '');
        $payloadRaw = $request->getContent();

        if (! $githubService->verifyWebhookSignature($payloadRaw, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();

        ProcessGitHubWebhookJob::dispatch($event, $payload);

        return response()->json(['status' => 'webhook_received', 'event' => $event]);
    }
}
