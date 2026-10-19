<?php

use App\Http\Controllers\InboundEmailController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook routes
|--------------------------------------------------------------------------
|
| Registered WITHOUT the `web` middleware group: no session, no CSRF token.
| A mail provider has no cookie jar and cannot present a CSRF token, so these
| routes are authenticated by the secret in the URL instead (see the
| VerifyInboundWebhook middleware).
|
| The URL given to the provider therefore looks like:
|   https://helpdesk.example.com/webhooks/inbound-email/<HELPDESK_INBOUND_SECRET>
|
*/

Route::post('/webhooks/inbound-email/{secret}', [InboundEmailController::class, 'store'])
    ->middleware('inbound.webhook')
    ->name('webhooks.inbound-email');
