<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactSubmission extends Model
{
    protected $fillable = ['name', 'email', 'company', 'store_url', 'topic', 'message', 'ip', 'status'];
}
