<?php

namespace App\Http\Controllers;

use App\Mail\TicketReplied;
use App\Models\Ticket;
use App\Models\TicketView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class MyTicketsController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        abort_if(! $user, 403);

        $email = $user->email;

        $tickets = Ticket::where('requester_email', $email)
            ->with('replies')
            ->orderByDesc('id')
            ->paginate(10);

        $unreadTicketIds = [];
        if ($tickets->count() > 0) {
            $views = TicketView::where('user_id', $user->id)
                ->whereIn('ticket_id', $tickets->pluck('id'))
                ->get()
                ->keyBy('ticket_id');

            $unreadTicketIds = $tickets->getCollection()->filter(function ($ticket) use ($views) {
                $latestReply = $ticket->replies->last();
                if (! $latestReply || $latestReply->author_role !== 'ops') {
                    return false;
                }

                $lastViewed = $views->get($ticket->id)?->last_viewed_at;

                return ! $lastViewed || $latestReply->created_at->gt($lastViewed);
            })->pluck('id')->all();
        }

        return view('my_tickets.index', compact('tickets', 'email', 'unreadTicketIds'));
    }

    public function show(Ticket $ticket)
    {
        $user = auth()->user();
        abort_if(! $user, 403);

        // Ops can view any ticket
        if (method_exists($user, 'isOps') && $user->isOps()) {
            $ticket->load(['replies.attachments', 'attachments']);
            TicketView::updateOrCreate(
                ['user_id' => $user->id, 'ticket_id' => $ticket->id],
                ['last_viewed_at' => now()]
            );

            return view('ops.tickets.show', compact('ticket'));
        }

        // Non-ops can only view their own tickets
        abort_if($ticket->requester_email !== $user->email, 403);

        $ticket->load(['replies.attachments', 'attachments']);
        TicketView::updateOrCreate(
            ['user_id' => $user->id, 'ticket_id' => $ticket->id],
            ['last_viewed_at' => now()]
        );

        return view('my_tickets.show', compact('ticket'));
    }

    public function storeReply(Request $request, Ticket $ticket)
    {
        $user = auth()->user();
        abort_if(! $user, 403);

        // Non-ops can only reply to their own tickets
        if (! (method_exists($user, 'isOps') && $user->isOps())) {
            abort_if($ticket->requester_email !== $user->email, 403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        // Create a reply from the requester/user
        $reply = $ticket->replies()->create([
            'author_role' => 'requester',
            'author_email' => $user->email,
            'message' => $validated['message'],
        ]);

        $opsEmail = config('helpdesk.ops_notify_email');
        if ($opsEmail) {
            Mail::to($opsEmail)->send(new TicketReplied($ticket, $reply, 'ops'));
        }

        return back()->with('success', 'Reply sent.');
    }
}
