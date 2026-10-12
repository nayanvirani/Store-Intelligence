<?php

namespace App\Models;

use App\Support\Plans;
use Illuminate\Database\Eloquent\Model;

/**
 * A billing plan, edited in the Internal Admin. Its key and Shopify name must match the plan
 * in the Partner Dashboard (Managed Pricing), which is what Shopify actually charges.
 */
class Plan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'float', 'trial_days' => 'integer', 'position' => 'integer',
            'modules' => 'array', 'limits' => 'array', 'features' => 'array', 'is_public' => 'boolean', 'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Plans::forget());
        static::deleted(fn () => Plans::forget());
    }
}
