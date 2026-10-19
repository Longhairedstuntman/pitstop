@props(['attachments'])

@if($attachments && $attachments->isNotEmpty())
    <div class="attachment-list" style="margin-top: 10px;">
        <div class="ticket-sub" style="font-size: 12px; margin-bottom: 6px;">
            {{ $attachments->count() }} {{ Str::plural('attachment', $attachments->count()) }}
        </div>

        <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 8px;">
            @foreach($attachments as $attachment)
                <li>
                    <a
                        class="btn"
                        style="font-size: 12px; padding: 4px 10px;"
                        href="{{ route('ticket_attachments.show', $attachment) }}"
                        title="{{ $attachment->original_name }} ({{ $attachment->humanSize() }})"
                    >
                        {{ $attachment->isImage() ? '🖼' : '📎' }}
                        {{ Str::limit($attachment->original_name, 32) }}
                        <span style="opacity: .6;">{{ $attachment->humanSize() }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
