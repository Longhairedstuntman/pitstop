<?php

namespace App\Jobs;

use App\Mail\Inbound\PostmarkInboundMapper;
use App\Services\Helpdesk\InboundEmailProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processes one inbound email off the queue.
 *
 * The webhook responds 200 immediately and defers to this, so a slow mailbox
 * or a large attachment can't cause the provider to time out and redeliver.
 * The raw provider payload is carried on the job, so a failure here is
 * replayable from the failed_jobs table rather than lost.
 */
class ProcessInboundEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(
        public array $payload,
        public string $provider = 'postmark',
    ) {}

    public function handle(InboundEmailProcessor $processor): void
    {
        $email = match ($this->provider) {
            'postmark' => (new PostmarkInboundMapper)->map($this->payload),
            default => throw new \InvalidArgumentException(
                "Unknown inbound mail provider [{$this->provider}]."
            ),
        };

        $result = $processor->process($email);

        Log::info('Helpdesk: inbound email processed', [
            'action' => $result['action'],
            'ticket_id' => $result['ticket']?->id,
            'reason' => $result['reason'],
            'from' => $email->fromEmail,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Helpdesk: inbound email processing failed permanently', [
            'provider' => $this->provider,
            'message_id' => $this->payload['MessageID'] ?? null,
            'from' => $this->payload['From'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
