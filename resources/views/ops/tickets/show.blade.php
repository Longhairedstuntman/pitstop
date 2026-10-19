<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request #{{ $ticket->id }}</title>

    @vite([
        'resources/css/app.css',
        'resources/css/ticket.css',
        'resources/js/app.js'
    ])
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <img
                    src="{{ asset('images/pitstop-logo.png') }}"
                    alt="PitStop"
                    class="brand-logo"
                />
                <div class="brand-title">
                    <strong>Request #{{ $ticket->id }}</strong>
                    <span>Ops Request Detail</span>
                </div>
            </div>

            <div class="topbar-actions" style="display:flex; gap:10px; align-items:center;">
                <a class="btn" href="{{ route('ops.tickets.index') }}">← Back to queue</a>
                <a class="btn" href="{{ url('/') }}">Dashboard</a>
                @if(auth()->user()->isAdmin())
                    <form method="POST" action="{{ route('ops.tickets.destroy', $ticket) }}" onsubmit="return confirm('Delete request #{{ $ticket->id }}? This cannot be undone.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn" style="border-color: rgba(255, 107, 107, 0.45); color: rgba(255, 107, 107, 0.95);">Delete Request</button>
                    </form>
                @endif
                <a class="btn btn-primary" href="{{ route('tickets.create') }}">+ Submit Request</a>

                <x-dropdown align="right" width="56">
                    <x-slot name="trigger">
                        <button type="button" class="btn" style="display:flex; gap:6px; align-items:center;">
                            Menu
                            <svg class="fill-current" style="height:16px;width:16px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('my_tickets.index')">My Requests</x-dropdown-link>

                        @if(auth()->user()->isOps())
                            <x-dropdown-link :href="route('ops.tickets.index')">Ops Queue</x-dropdown-link>
                        @endif

                        @if(auth()->user()->isAdmin())
                            <x-dropdown-link :href="route('admin.users.index')">User Management</x-dropdown-link>
                        @endif

                        <x-dropdown-link :href="route('profile.edit')">Settings</x-dropdown-link>

                        <div class="border-t border-gray-100 my-1"></div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                onclick="event.preventDefault(); this.closest('form').submit();">
                                Log out
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </header>

    <main class="shell page">
        <div class="ticket-head">
            <div>
                <h1 class="ticket-title">Request #{{ $ticket->id }}</h1>
                <div class="ticket-sub">
                    Submitted {{ $ticket->created_at->format('Y-m-d g:i A') }} by {{ $ticket->requester_email }}
                </div>

                <div class="ticket-meta" aria-label="Request tags">
                    <span class="ticket-pill">{{ $ticket->status }}</span>
                    <span class="ticket-pill">{{ $ticket->priority }}</span>
                    <span class="ticket-pill">{{ $ticket->category }}</span>
                    @if($ticket->source === \App\Models\Ticket::SOURCE_EMAIL)
                        <span class="ticket-pill" title="Opened from an email to the help desk address">✉ via email</span>
                    @endif
                </div>
            </div>

            <div class="pill">Tip: update status on the right.</div>
        </div>

        @if(session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif

        <div class="ticket-grid" style="margin-top: 14px;">
            <section class="card" aria-label="Request details">
                <div style="padding: 16px 18px;">
                    <h2 class="ticket-section-title">Subject</h2>
                    <div class="ticket-body">{{ $ticket->subject }}</div>

                    <div style="height: 14px;"></div>

                    <h2 class="ticket-section-title">Description</h2>
                    <div class="ticket-body">{{ $ticket->description }}</div>

                    <x-attachment-list :attachments="$ticket->attachments" />
                </div>
            </section>

            @if(auth()->check() && auth()->user()->role === 'admin')
                <section class="card" aria-label="Update status">
                    <div style="padding: 16px 18px;">
                        <h2 class="ticket-section-title">Update Status</h2>

                        <form method="POST" action="{{ route('ops.tickets.status', $ticket) }}">
                            @csrf

                            <div class="field">
                                <label class="label" for="status">Status</label>
                                <select id="status" name="status" class="select" required>
                                    @foreach(['Open','In Progress','Waiting','Closed'] as $s)
                                        <option value="{{ $s }}" @selected($ticket->status === $s)>{{ $s }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save</button>
                            </div>
                        </form>
                    </div>
                </section>
            @else
                <section class="card" aria-label="Status permissions">
                    <div style="padding: 16px 18px;">
                        <h2 class="ticket-section-title">Update Status</h2>
                        <div class="ticket-sub">Only admins can update request status.</div>
                    </div>
                </section>
            @endif
        </div>

        <section class="card" aria-label="Replies" style="margin-top: 14px;">
            <div style="padding: 16px 18px;">
                <h2 class="ticket-section-title">Replies</h2>

                <div class="replies">
                    @forelse($ticket->replies as $reply)
                        <div class="reply">
                            <div class="reply-head">
                                <div class="reply-author">
                                    <span class="ticket-pill">{{ strtolower($reply->author_role) }}</span>
                                    @if($reply->author_email)
                                        <span>{{ $reply->author_email }}</span>
                                    @endif
                                    @if($reply->cameFromEmail())
                                        <span class="ticket-pill" title="Received by email">✉</span>
                                    @endif
                                </div>
                                <div>{{ $reply->created_at->format('Y-m-d g:i A') }}</div>
                            </div>

                            <div class="reply-body">{{ $reply->message }}</div>

                            <x-attachment-list :attachments="$reply->attachments" />
                        </div>
                    @empty
                        <div class="ticket-sub" style="margin-top: 10px;">No replies yet.</div>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="card" aria-label="Post a reply" style="margin-top: 14px;">
            <div style="padding: 16px 18px;">
                <h2 class="ticket-section-title">Post a Reply</h2>

                <div class="ticket-sub" style="margin-bottom: 10px; font-size: 12px;">
                    This emails {{ $ticket->requester_email }} from the help desk address.
                    Their reply comes straight back onto this request.
                </div>

                <form method="POST" action="{{ route('ops.tickets.reply', $ticket) }}" enctype="multipart/form-data">
                    @csrf

                    <div class="field">
                        <label class="label" for="message">Message</label>
                        <textarea id="message" class="textarea" name="message" rows="5" required></textarea>
                    </div>

                    <div class="field" style="margin-top: 12px;">
                        <label class="label" for="attachments">Attachments</label>
                        <input
                            id="attachments"
                            name="attachments[]"
                            type="file"
                            multiple
                            accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
                        >
                        <div class="ticket-sub" style="margin-top: 6px; font-size: 12px;">
                            Optional. Add screenshots, PDFs, or other files (up to ~10MB each).
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Send Reply</button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</body>
</html>