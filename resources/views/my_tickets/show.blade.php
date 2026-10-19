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
                <div class="brand-mark" aria-hidden="true"></div>
                <div class="brand-title">
                    <strong>Request #{{ $ticket->id }}</strong>
                    <span>My Request</span>
                </div>
            </div>

            <div class="topbar-actions" style="display:flex; gap:10px; align-items:center;">
                <a class="btn" href="{{ route('my_tickets.index') }}">← My Requests</a>
                <a class="btn" href="{{ url('/') }}">Dashboard</a>
                <a class="btn btn-primary" href="{{ route('tickets.create') }}">+ New Request</a>

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
                <div class="ticket-sub">Submitted {{ $ticket->created_at->format('M j, Y \a\t g:i A') }}</div>

                <div class="ticket-meta" aria-label="Request tags">
                    <span class="ticket-pill">{{ $ticket->status }}</span>
                    <span class="ticket-pill">{{ $ticket->priority }}</span>
                    <span class="ticket-pill">{{ $ticket->category }}</span>
                </div>
            </div>
        </div>

        @if(session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <section class="card" aria-label="Errors" style="margin-top: 14px;">
                <div style="padding: 16px 18px;">
                    <h2 class="ticket-section-title">Please fix the following</h2>
                    <div class="ticket-sub" style="margin-top: 10px;">
                        <ul style="margin:0; padding-left: 18px;">
                            @foreach($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </section>
        @endif

        <section class="card" aria-label="Request details" style="margin-top: 14px;">
            <div style="padding: 16px 18px;">
                <h2 class="ticket-section-title">Subject</h2>
                <div class="ticket-body">{{ $ticket->subject }}</div>

                <div style="height: 14px;"></div>

                <h2 class="ticket-section-title">Description</h2>
                <div class="ticket-body">{{ $ticket->description }}</div>

                <x-attachment-list :attachments="$ticket->attachments" />
            </div>
        </section>

        <section class="card" aria-label="Conversation" style="margin-top: 14px;">
            <div style="padding: 16px 18px;">
                <h2 class="ticket-section-title">Conversation</h2>

                <div class="replies">
                    @forelse($ticket->replies->sortByDesc('created_at') as $reply)
                        <div class="reply">
                            <div class="reply-head">
                                <div class="reply-author">
                                    <span class="ticket-pill">
                                        {{ $reply->author_role === 'ops' ? 'ops' : 'requester' }}
                                    </span>
                                </div>
                                <div>{{ $reply->created_at->format('M j, Y g:i A') }}</div>
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

        @php
            $replyAction = \Route::has('my_tickets.reply')
                ? route('my_tickets.reply', $ticket)
                : (\Route::has('tickets.reply') ? route('tickets.reply', $ticket) : null);
        @endphp

        <section class="card" aria-label="Post a reply" style="margin-top: 14px;">
            <div style="padding: 16px 18px;">
                <h2 class="ticket-section-title">Post a Reply</h2>
                <div class="ticket-sub" style="margin-top: 6px;">Send a message to Ops. Your reply will appear at the top of the conversation.</div>

                @if($replyAction)
                    <form method="POST" action="{{ $replyAction }}" style="margin-top: 12px;" enctype="multipart/form-data">
                        @csrf

                        <div class="field">
                            <label class="label" for="message">Message</label>
                            <textarea id="message" class="textarea" name="message" rows="5" required>{{ old('message') }}</textarea>
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
                                Optional. Add screenshots, PDFs, or other files to support your reply (up to ~10MB each).
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Send Reply</button>
                        </div>
                    </form>
                @else
                    <div class="ticket-sub" style="margin-top: 10px;">Reply route is not configured yet. Add a POST route named <strong>my_tickets.reply</strong> (or <strong>tickets.reply</strong>) to enable replies from users.</div>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
