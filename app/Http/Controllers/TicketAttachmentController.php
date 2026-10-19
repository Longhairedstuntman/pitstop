<?php

namespace App\Http\Controllers;

use App\Models\TicketAttachment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentController extends Controller
{
    /**
     * Stream a ticket attachment to an authorised viewer.
     *
     * Attachments live on a private disk precisely so this check can exist:
     * ops see everything, a requester sees only files on their own tickets,
     * and nobody gets at them by guessing a URL.
     */
    public function show(TicketAttachment $attachment): StreamedResponse
    {
        $user = Auth::user();
        abort_if(!$user, 403);

        $ticket = $attachment->ticket;
        abort_if(!$ticket, 404);

        $isOps = method_exists($user, 'isOps') && $user->isOps();

        if (!$isOps) {
            abort_if($ticket->requester_email !== $user->email, 403);
        }

        abort_unless($attachment->exists(), 404);

        // Always download rather than render inline: an attacker-supplied HTML
        // or SVG file rendered on our origin is a stored-XSS vector, and every
        // one of these files came from an unauthenticated sender.
        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]
        );
    }
}
