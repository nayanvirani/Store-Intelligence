<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'store_id', 'plan', 'shopify_subscription_id', 'status', 'price', 'test',
        'trial_ends_at', 'current_period_ends_at', 'activated_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'test' => 'boolean',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
