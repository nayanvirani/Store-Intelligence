<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Final MVP F3: the costs profit uses besides product cost.
 * payment_fee {percent, fixed, gateway?} · shipping {basis: order|item, amount, country?}
 * handling {amount} per order · fixed_cost {amount} per month · ad_spend {amount, channel} per month (channels not connected).
 */
class CostRule extends Model
{
    public const TYPES = [
        'payment_fee' => 'Payment fee',
        'shipping' => 'Shipping cost',
        'handling' => 'Handling & packaging',
        'fixed_cost' => 'Fixed monthly cost',
        'ad_spend' => 'Other ad spend',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['config' => 'array', 'active' => 'boolean'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function describe(string $currency): string
    {
        $c = $this->config ?? [];
        $money = fn ($v) => money((float) $v, $currency);

        return match ($this->type) {
            'payment_fee' => rtrim(rtrim(number_format((float) ($c['percent'] ?? 0), 2), '0'), '.').'% + '.$money($c['fixed'] ?? 0).' per order'.(! empty($c['gateway']) ? ' ('.$c['gateway'].')' : ''),
            'shipping' => $money($c['amount'] ?? 0).' per '.(($c['basis'] ?? 'order') === 'item' ? 'item' : 'order').(! empty($c['country']) ? ' to '.$c['country'] : ''),
            'handling' => $money($c['amount'] ?? 0).' per order',
            'fixed_cost', 'ad_spend' => $money($c['amount'] ?? 0).' per month',
            default => '',
        };
    }
}
