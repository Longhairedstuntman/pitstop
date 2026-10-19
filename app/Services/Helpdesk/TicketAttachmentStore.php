<?php

namespace App\Services\Helpdesk;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Persists web-uploaded attachments and records them in `ticket_attachments`.
 *
 * Previously uploads were written to the `public` disk and only mentioned in
 * a log line — they were unreachable from the UI and readable by anyone who
 * guessed the path. Files now go to a private disk with a database row, and
 * are served through TicketAttachmentController.
 */
class TicketAttachmentStore
{
    /**
     * @param  array<int, UploadedFile|null>  $files
     * @param  Ticket|TicketReply  $attachable
     * @return int number of files stored
     */
    public function storeUploads(array $files, Ticket $ticket, $attachable): int
    {
        $disk = (string) config('helpdesk.attachment_disk', 'local');
        $stored = 0;

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $originalName = $this->safeName($file->getClientOriginalName());
            $path = "helpdesk/tickets/{$ticket->id}/".Str::uuid().'-'.$originalName;

            try {
                $file->storeAs(dirname($path), basename($path), ['disk' => $disk]);
            } catch (\Throwable $e) {
                Log::error('Helpdesk: failed storing uploaded attachment', [
                    'ticket_id' => $ticket->id,
                    'name' => $originalName,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'attachable_type' => $attachable::class,
                'attachable_id' => $attachable->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $originalName,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize() ?: 0,
            ]);

            $stored++;
        }

        return $stored;
    }

    /**
     * Client-supplied filenames are attacker-controlled; flatten to a basename
     * and strip anything that isn't a plain filename character.
     */
    protected function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? 'attachment';
        $name = trim(ltrim($name, '.'));

        return mb_substr($name === '' ? 'attachment' : $name, 0, 180);
    }
}
