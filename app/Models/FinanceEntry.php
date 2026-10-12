<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A manual expense or extra income in the Internal Admin (Finance). */
class FinanceEntry extends Model
{
    protected $fillable = ['kind', 'date', 'description', 'category', 'amount', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }
}
