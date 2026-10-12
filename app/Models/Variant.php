<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Variant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'shopify_cost' => 'decimal:4',
            'app_cost' => 'decimal:4',
            'inventory_tracked' => 'boolean',
            'requires_shipping' => 'boolean',
            'shopify_updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function costSnapshots(): HasMany
    {
        return $this->hasMany(CostSnapshot::class);
    }

    /** The unit cost profit uses: the merchant's cost in the app wins over Shopify's "Cost per item". */
    public function effectiveCost(): ?float
    {
        $cost = $this->app_cost ?? $this->shopify_cost;

        return $cost === null ? null : (float) $cost;
    }

    public function costSource(): ?string
    {
        return $this->app_cost !== null ? 'app' : ($this->shopify_cost !== null ? 'shopify' : null);
    }

    public function scopeLive($query)
    {
        return $query->whereNull('deleted_at');
    }
}
