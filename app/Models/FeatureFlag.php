<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    protected $fillable = ['key', 'description', 'enabled', 'store_ids'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'store_ids' => 'array'];
    }
}
