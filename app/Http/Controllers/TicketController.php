<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use App\Mail\NewTicketSubmitted;
use App\Models\TicketView;
use App\Services\Helpdesk\OutboundTicketMailer;
use App\Services\Helpdesk\TicketAttachmentStore;

class TicketController extends Controller
{
    public function create()
    {
        return view('tickets.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject'     => ['required', 'string', 'max:150'],
            'description'     => ['required', 'string', 'max:5000'],
            'category'        => ['required', 'string', 'max:50', Rule::in(array_keys(Ticket::CATEGORY_PRIORITY_MAP))],
            'attachments'   => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt'],
        ]);

        $priority = Ticket::CATEGORY_PRIORITY_MAP[$validated['category']] ?? 'Normal';
        $ticketData = Arr::except($validated, ['attachments']);

        $ticket = Ticket::create([
            ...$ticketData,
            'priority' => $priority,
            'requester_email' => Auth::user()->email,
            'status' => 'Open',
        ]);

        // Attachments go to a private disk and are recorded in the database,
        // so they're downloadable from the ticket page and not readable by
        // anyone who guesses a storage URL.
        if ($request->hasFile('attachments')) {
            app(TicketAttachmentStore::class)->storeUploads(
                $request->file('attachments'),
                $ticket,
                $ticket
            );
        }

        $opsEmail = config('helpdesk.ops_notify_email');
        if ($opsEmail) {
            Mail::to($opsEmail)->send(new NewTicketSubmitted($ticket));
        }

        return back()->with('success', "Ticket #{$ticket->id} submitted successfully.");
    }

    public function index()
    {
       $tickets = \App\Models\Ticket::orderByDesc('created_at')->get();
       return view('ops.tickets.index', compact('tickets'));
    }

    public function show(Ticket $ticket)
    {
       $ticket->load('replies');
       return view('ops.tickets.show', compact('ticket'));
    }

    public function updateStatus(Request $request, Ticket $ticket)
    {
       $validated = $request->validate([
           'status' => ['required', 'in:Open,In Progress,Waiting,Closed'],
       ]);

       $ticket->status = $validated['status'];
       $ticket->save();

       return back()->with('success', 'Status updated.');
    }

    public function storeReply(Request $request, Ticket $ticket)
    {
       $validated = $request->validate([
           'message' => ['required', 'string', 'max:5000'],
           'author_email' => ['nullable', 'email', 'max:255'],
           'attachments'   => ['nullable', 'array', 'max:10'],
           'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt'],
       ]);

       $reply = $ticket->replies()->create([
            'author_role'  => 'ops',
          'author_email' => Auth::user()?->email,
            'message'      => $validated['message'],
        ]);

        // Attachments on the reply itself, stored privately and recorded.
        if ($request->hasFile('attachments')) {
            app(TicketAttachmentStore::class)->storeUploads(
                $request->file('attachments'),
                $ticket,
                $reply
            );
        }

        // Sent through the mailer rather than Mail::send directly so the
        // outbound Message-ID is recorded and the requester's reply threads
        // back onto this ticket instead of opening a new one.
        app(OutboundTicketMailer::class)->sendReplyToRequester($ticket, $reply);

       if ($ticket->status === 'Open') {
           $ticket->update(['status' => 'In Progress']);
       }

       return back()->with('success', 'Reply posted.');
    }

    public function opsIndex()
    {
        $statusFilter = strtolower((string) request('status', ''));

        $ticketsQuery = Ticket::query();

        if ($statusFilter === 'closed') {
            $ticketsQuery->whereIn('status', ['Closed', 'Resolved']);
        } elseif ($statusFilter !== '') {
            $ticketsQuery->where('status', ucfirst($statusFilter));
        } else {
            $ticketsQuery->whereNotIn('status', ['Closed', 'Resolved']);
        }

        $priorityOrder = "case priority when 'Critical' then 4 when 'High' then 3 when 'Normal' then 2 when 'Low' then 1 else 0 end";

        $tickets = $ticketsQuery
            ->with(['replies' => fn ($query) => $query->latest('created_at')->limit(1)])
            ->orderByRaw("{$priorityOrder} desc")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->appends(request()->query());

        $unreadTicketIds = [];
        $user = Auth::user();
        if ($user && $tickets->count() > 0) {
            $views = TicketView::where('user_id', $user->id)
                ->whereIn('ticket_id', $tickets->pluck('id'))
                ->get()
                ->keyBy('ticket_id');

            $unreadTicketIds = $tickets->getCollection()->filter(function ($ticket) use ($views) {
                $latestReply = $ticket->replies->first();
                if (!$latestReply || $latestReply->author_role !== 'requester') {
                    return false;
                }

                $lastViewed = $views->get($ticket->id)?->last_viewed_at;

                return !$lastViewed || $latestReply->created_at->gt($lastViewed);
            })->pluck('id')->all();
        }

        return view('ops.tickets.index', compact('tickets', 'unreadTicketIds'));
    }

    public function opsShow(Ticket $ticket)
    {
        // Eager-load attachments on both the ticket and every reply so the
        // attachment lists don't fire a query per reply.
        $ticket->load(['replies.attachments', 'attachments']);
        $user = Auth::user();
        if ($user) {
            TicketView::updateOrCreate(
                ['user_id' => $user->id, 'ticket_id' => $ticket->id],
                ['last_viewed_at' => now()]
            );
        }
        return view('ops.tickets.show', compact('ticket'));
    }

    public function opsUpdateStatus(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:30'],
        ]);

        $ticket->update([
            'status' => $data['status'],
        ]);

        return back()->with('success', 'Status updated.');
    }

    public function opsDestroy(Ticket $ticket)
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        abort_if(!$user || !$user->isAdmin(), 403);

        $ticketId = $ticket->id;
        $ticket->delete();

        return redirect()
            ->route('ops.tickets.index')
            ->with('success', "Ticket #{$ticketId} deleted.");
    }

}
