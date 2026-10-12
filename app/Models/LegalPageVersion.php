<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalPageVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_at' => 'date', 'published_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LegalPage::class, 'legal_page_id');
    }
}
