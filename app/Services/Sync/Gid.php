<?php

namespace App\Services\Sync;

/** Shopify global IDs: gid://shopify/Product/123 ⇄ 123. */
class Gid
{
    public static function id(?string $gid): ?int
    {
        if ($gid === null || $gid === '') {
            return null;
        }
        $id = (int) substr((string) strrchr($gid, '/'), 1);

        return $id > 0 ? $id : null;
    }

    public static function make(string $type, int|string $id): string
    {
        return "gid://shopify/{$type}/{$id}";
    }
}
