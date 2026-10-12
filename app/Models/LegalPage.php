<?php

namespace App\Models;

use App\Support\Legal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalPage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_at' => 'date', 'is_published' => 'boolean', 'show_in_footer' => 'boolean', 'requires_review' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Legal::forget());
        static::deleted(fn () => Legal::forget());
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LegalPageVersion::class)->orderByDesc('version');
    }

    public function hasDraft(): bool
    {
        return $this->draft_body !== null && ($this->draft_body !== $this->body || ($this->draft_title ?? $this->title) !== $this->title);
    }

    public function url(): string
    {
        return Legal::url($this->slug);
    }
}
