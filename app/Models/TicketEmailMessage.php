<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketEmailMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'ticket_id',
        'ticket_reply_id',
        'direction',
        'message_id',
        'in_reply_to',
        'references',
        'from_email',
        'subject',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function reply()
    {
        return $this->belongsTo(TicketReply::class, 'ticket_reply_id');
    }

    /**
     * Normalise a raw Message-ID header value for storage and lookup.
     *
     * Strips the angle brackets, trims whitespace, lowercases (Message-IDs are
     * case-insensitive in practice and mail servers are inconsistent), and
     * truncates to the indexed column width.
     */
    public static function normaliseId(?string $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $clean = trim($messageId);
        $clean = trim($clean, '<>');
        $clean = trim($clean);

        if ($clean === '') {
            return null;
        }

        return mb_strtolower(mb_substr($clean, 0, 255));
    }

    /**
     * Pull every Message-ID out of a References-style header.
     *
     * References is a space-separated list of <id> tokens, but plenty of
     * clients separate with commas or newlines, so split on any whitespace
     * and comma and keep whatever looks like an id.
     */
    public static function parseReferences(?string $references): array
    {
        if ($references === null || trim($references) === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $references) ?: [];

        return array_values(array_filter(array_map(
            fn ($part) => self::normaliseId($part),
            $parts
        )));
    }
}
