<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Ticket extends Model
{
    public const SOURCE_WEB = 'web';

    public const SOURCE_EMAIL = 'email';

    /**
     * Statuses that mean the ticket is finished. An inbound email on one of
     * these reopens the ticket rather than starting a new one.
     */
    public const CLOSED_STATUSES = ['Closed', 'Resolved'];

    protected $fillable = [
        'requester_email',
        'requester_name',
        'subject',
        'description',
        'category',
        'priority',
        'status',
        'source',
        'public_token',
        'last_requester_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_requester_message_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            if (blank($ticket->public_token)) {
                $ticket->public_token = static::generateToken();
            }
        });
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::lower(Str::random(12));
        } while (static::where('public_token', $token)->exists());

        return $token;
    }

    public function replies()
    {
        return $this->hasMany(\App\Models\TicketReply::class)
            ->orderBy('created_at');
    }

    public function emailMessages()
    {
        return $this->hasMany(TicketEmailMessage::class);
    }

    /**
     * Attachments on the opening message only. Reply attachments hang off
     * the reply; `allAttachments` spans both.
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(TicketAttachment::class, 'attachable');
    }

    public function allAttachments()
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * The tag appended to outbound subjects, e.g. "[#PS-412-a1b2c3d4e5f6]".
     *
     * Including the token means a forwarded subject line can't be used to
     * graft a message onto someone else's ticket by guessing the id.
     */
    public function subjectTag(): string
    {
        return '[#'.config('helpdesk.subject_prefix', 'PS').'-'.$this->id.'-'.$this->public_token.']';
    }

    /**
     * Subject with the tag appended, unless it is already present.
     */
    public function taggedSubject(?string $subject = null): string
    {
        $subject = $subject ?? (string) $this->subject;
        $tag = $this->subjectTag();

        return str_contains($subject, $tag)
            ? $subject
            : trim($subject).' '.$tag;
    }

    /**
     * Plus-addressed reply address, e.g. "helpdesk+412a1b2c3d4e5f6@mwmotor.com".
     *
     * Postmark surfaces everything after the "+" as MailboxHash, giving a
     * third way to resolve the thread when headers and subject both fail.
     */
    public function replyToAddress(): ?string
    {
        $address = config('helpdesk.inbound_address');

        if (blank($address) || ! str_contains($address, '@')) {
            return null;
        }

        [$mailbox, $domain] = explode('@', $address, 2);

        return $mailbox.'+'.$this->mailboxHash().'@'.$domain;
    }

    public function mailboxHash(): string
    {
        return $this->id.$this->public_token;
    }

    /**
     * Resolve a ticket from a MailboxHash produced by `mailboxHash()`.
     */
    public static function findByMailboxHash(?string $hash): ?self
    {
        if (blank($hash)) {
            return null;
        }

        if (! preg_match('/^(\d+)([a-z0-9]{6,32})$/i', trim($hash), $matches)) {
            return null;
        }

        return static::where('id', (int) $matches[1])
            ->where('public_token', mb_strtolower($matches[2]))
            ->first();
    }

    /**
     * Resolve a ticket from a subject line containing a subject tag.
     */
    public static function findBySubjectTag(?string $subject): ?self
    {
        if (blank($subject)) {
            return null;
        }

        $prefix = preg_quote((string) config('helpdesk.subject_prefix', 'PS'), '/');

        if (! preg_match('/\[#'.$prefix.'-(\d+)-([a-z0-9]{6,32})\]/i', $subject, $matches)) {
            return null;
        }

        return static::where('id', (int) $matches[1])
            ->where('public_token', mb_strtolower($matches[2]))
            ->first();
    }
}
