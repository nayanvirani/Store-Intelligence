<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreUser extends Model
{
    public const ROLES = ['owner', 'admin', 'marketer', 'analyst'];

    protected $fillable = [
        'store_id', 'shopify_user_id', 'first_name', 'last_name', 'email', 'account_owner',
        'role', 'invited_at', 'invited_by', 'disabled_at', 'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'account_owner' => 'boolean',
            'invited_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function can(string $permission): bool
    {
        return $this->disabled_at === null && Permissions::allows($this->role, $permission);
    }

    public function isPendingInvite(): bool
    {
        return $this->shopify_user_id === null;
    }

    public function status(): string
    {
        return match (true) {
            $this->disabled_at !== null => 'removed',
            $this->isPendingInvite() => 'pending',
            default => 'active',
        };
    }

    public function displayName(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : ($this->email ?? 'Shopify staff #'.$this->shopify_user_id);
    }
}
