<?php

namespace App\Support\Spa;

use App\Models\Store;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * Turns page props into plain JSON. A Store is never sent (it holds credentials).
 */
class Props
{
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Store => null,
            $value instanceof Closure => self::normalize($value()),
            $value instanceof LengthAwarePaginator => [
                'data' => self::normalize($value->items()),
                'page' => $value->currentPage(), 'last_page' => $value->lastPage(),
                'total' => $value->total(), 'per_page' => $value->perPage(),
            ],
            $value instanceof Model => self::normalize(self::withoutStore($value)->toArray()),
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Arrayable => self::normalize($value->toArray()),
            $value instanceof JsonSerializable => self::normalize($value->jsonSerialize()),
            is_array($value) => array_map(self::normalize(...), $value),
            default => $value,
        };
    }

    private static function withoutStore(Model $model): Model
    {
        return $model->relationLoaded('store') ? (clone $model)->unsetRelation('store') : $model;
    }
}
