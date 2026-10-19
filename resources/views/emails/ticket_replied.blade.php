@php
	$recipientType = $recipientType ?? 'requester';
	$isOpsRecipient = $recipientType === 'ops';
@endphp

<h2>{{ $isOpsRecipient ? 'New reply on an Ops Request' : 'Update on your request' }}</h2>

<p><strong>Request:</strong> #{{ $ticket->id }}</p>
<p><strong>Subject:</strong> {{ $ticket->subject }}</p>
<p><strong>Status:</strong> {{ $ticket->status }}</p>

<p><strong>{{ $isOpsRecipient ? 'Requester reply:' : 'Our reply:' }}</strong></p>
<div style="white-space: pre-wrap;">{{ $reply->message }}</div>

@if($reply->attachments->isNotEmpty())
	<p><strong>Attachments:</strong></p>
	<ul>
		@foreach($reply->attachments as $attachment)
			<li>{{ $attachment->original_name }} ({{ $attachment->humanSize() }})</li>
		@endforeach
	</ul>
@endif

<hr>

@if($isOpsRecipient)
	<p>View request:</p>
	<p><a href="{{ url('/ops/tickets/' . $ticket->id) }}">{{ url('/ops/tickets/' . $ticket->id) }}</a></p>
@else
	<p><strong>To respond, just reply to this email.</strong> Your reply is added
	to request #{{ $ticket->id }} automatically.</p>

	<p style="color: #888; font-size: 12px;">Please keep the subject line intact
	so your reply reaches the right request.</p>
@endif
