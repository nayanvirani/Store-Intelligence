<?php

namespace App\Models\Support;

use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $table = 'support_tickets';

    protected $fillable = ['store_id', 'store_user_id', 'subject', 'category', 'priority', 'status', 'assigned_to', 'diagnostics', 'resolution', 'resolved_at', 'last_reply_at', 'last_reply_by'];

    public const CATEGORIES = ['setup' => 'Setup', 'profit' => 'Profit numbers', 'sync' => 'Data sync', 'integrations' => 'Connected accounts', 'alerts' => 'Alerts & emails', 'billing' => 'Billing', 'technical' => 'Something else technical'];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent: my store is affected'];

    public const STATUSES = ['open' => 'Open', 'pending' => 'Waiting for you', 'resolved' => 'Resolved', 'closed' => 'Closed'];

    protected function casts(): array
    {
        return ['diagnostics' => 'array', 'resolved_at' => 'datetime', 'last_reply_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(StoreUser::class, 'store_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'ticket_id')->orderBy('id');
    }

    public function reference(): string
    {
        return 'OO-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}
