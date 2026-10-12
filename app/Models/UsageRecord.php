<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    protected $fillable = ['store_id', 'meter', 'period_start', 'quantity'];
}
