<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InboundEmailController extends Controller
{
    /**
     * Receive one parsed email from the inbound provider.
     *
     * Always answers 200 for a well-formed request, including for mail we
     * intend to drop. A non-200 makes the provider retry, and retrying a
     * message we deliberately ignored achieves nothing but noise.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (!is_array($payload) || $payload === []) {
            // Not JSON, or empty — a 422 stops Postmark retrying something
            // that will never parse.
            return response()->json(['message' => 'Expected a JSON payload.'], 422);
        }

        ProcessInboundEmail::dispatch($payload, 'postmark');

        Log::info('Helpdesk: inbound email queued', [
            'message_id' => $payload['MessageID'] ?? null,
            'from' => $payload['From'] ?? null,
            'subject' => $payload['Subject'] ?? null,
        ]);

        return response()->json(['message' => 'Accepted.'], 200);
    }
}
