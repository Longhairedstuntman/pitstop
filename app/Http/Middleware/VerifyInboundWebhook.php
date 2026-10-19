<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the inbound-mail webhook.
 *
 * Postmark's inbound webhook carries NO signature — there is nothing to
 * verify cryptographically — so authenticity rests on two things the provider
 * can be configured to send and an attacker cannot guess:
 *
 *   1. A long random token embedded in the webhook URL path.
 *   2. Optional HTTP basic-auth credentials in the webhook URL.
 *
 * Without this, anyone who learns the endpoint can forge tickets from any
 * address, which is also a way to make the system email arbitrary people.
 */
class VerifyInboundWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('helpdesk.inbound_secret');

        // Fail closed. An unconfigured secret must not mean an open endpoint.
        if ($expected === '') {
            Log::error('Helpdesk: inbound webhook hit but HELPDESK_INBOUND_SECRET is not set; rejecting.');

            return response()->json(['message' => 'Inbound endpoint not configured.'], 503);
        }

        $provided = (string) $request->route('secret');

        if (!hash_equals($expected, $provided)) {
            Log::warning('Helpdesk: inbound webhook rejected, bad token', [
                'ip' => $request->ip(),
            ]);

            // 403 tells Postmark to stop retrying, which is what we want for
            // a request that will never become valid.
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $user = (string) config('helpdesk.inbound_basic_auth_user');
        $password = (string) config('helpdesk.inbound_basic_auth_password');

        if ($user !== '' || $password !== '') {
            $givenUser = (string) $request->getUser();
            $givenPassword = (string) $request->getPassword();

            $userOk = hash_equals($user, $givenUser);
            $passwordOk = hash_equals($password, $givenPassword);

            if (!$userOk || !$passwordOk) {
                Log::warning('Helpdesk: inbound webhook rejected, bad basic auth', [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        return $next($request);
    }
}
