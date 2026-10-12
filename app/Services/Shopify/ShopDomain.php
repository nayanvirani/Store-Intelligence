<?php

namespace App\Services\Shopify;

class ShopDomain
{
    public static function isValid(?string $shop): bool
    {
        return $shop !== null && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop) === 1;
    }
}
