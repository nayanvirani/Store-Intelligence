<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'shopify_updated_at' => 'datetime',
            'test' => 'boolean',
            'gross_sales' => 'decimal:2',
            'total_discounts' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_shipping' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_price' => 'decimal:2',
            'total_refunded' => 'decimal:2',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Orders that count in reports: not cancelled, and not test orders unless the store includes them. */
    public function scopeCounted($query, Store $store)
    {
        return $query->where('store_id', $store->id)->whereNull('cancelled_at')
            ->when($store->setting('exclude_test_orders'), fn ($q) => $q->where('test', false));
    }
}
