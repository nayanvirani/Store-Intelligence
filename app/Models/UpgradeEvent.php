<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UpgradeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** The feature or limit an upgrade click came from, as merchants see it named. */
    public static function label(string $feature): string
    {
        return match (true) {
            isset(\App\Support\Modules::ALL[$feature]) => \App\Support\Modules::label($feature),
            isset(\App\Services\Usage::METERS[$feature]) => \App\Services\Usage::METERS[$feature].' limit',
            default => ucfirst(str_replace('_', ' ', $feature)),
        };
    }
}
