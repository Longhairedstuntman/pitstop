<h2>We've got your request</h2>

<p>Thanks for getting in touch — your request has been logged and someone from
the team will pick it up.</p>

<p><strong>Request:</strong> #{{ $ticket->id }}</p>
<p><strong>Subject:</strong> {{ $ticket->subject }}</p>
<p><strong>Status:</strong> {{ $ticket->status }}</p>

<p><strong>What you sent us:</strong></p>
<div style="white-space: pre-wrap; padding: 8px 12px; border-left: 3px solid #ddd;">{{ $ticket->description }}</div>

<hr>

<p><strong>Need to add something?</strong> Just reply to this email — it will be
added to request #{{ $ticket->id }} automatically. There's no need to fill
anything else in.</p>

<p style="color: #888; font-size: 12px;">Please keep the subject line intact so
your reply reaches the right request.</p>
