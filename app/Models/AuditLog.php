<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id', 'context', 'request_id',
    ];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * Records an action. The actor is the signed-in staff member when there is one,
     * otherwise the system (webhooks, jobs).
     */
    public static function record(string $action, ?Store $store = null, array $context = [], ?Model $entity = null): self
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $user = $request?->attributes->get('storeUser');

        return static::create([
            'store_id' => $store?->id,
            'actor_type' => $user ? 'store_user' : 'system',
            'actor_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'context' => $context ?: null,
            'request_id' => $request?->header('X-Request-Id'),
        ]);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(StoreUser::class, 'actor_id');
    }

    public function store(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
