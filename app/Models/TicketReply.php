<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TicketReply extends Model
{
    public const ROLE_OPS = 'ops';

    public const ROLE_REQUESTER = 'requester';

    public const SOURCE_WEB = 'web';

    public const SOURCE_EMAIL = 'email';

    protected $fillable = [
        'ticket_id',
        'author_role',
        'author_email',
        'author_name',
        'message',
        'message_html',
        'source',
    ];

    public function ticket()
    {
        return $this->belongsTo(\App\Models\Ticket::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(TicketAttachment::class, 'attachable');
    }

    public function emailMessage()
    {
        return $this->hasOne(TicketEmailMessage::class, 'ticket_reply_id');
    }

    public function isFromOps(): bool
    {
        return $this->author_role === self::ROLE_OPS;
    }

    public function cameFromEmail(): bool
    {
        return $this->source === self::SOURCE_EMAIL;
    }
}
