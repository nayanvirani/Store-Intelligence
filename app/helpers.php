<?php

if (! function_exists('app_route')) {
    /**
     * URL to an embedded-app route that keeps the shop/host query App Bridge needs.
     */
    function app_route(string $name, array $parameters = []): string
    {
        $request = request();

        return route($name, array_filter(array_merge([
            'shop' => $request->query('shop') ?? $request->attributes->get('store')?->shop_domain,
            'host' => $request->query('host'),
        ], $parameters)));
    }
}

if (! function_exists('lower_label')) {
    /**
     * Lower-cases a label for use mid-sentence but keeps acronyms: "BOGO offer", "sticky add to cart".
     */
    function lower_label(string $label): string
    {
        return preg_replace_callback('/\b[A-Z][a-z]+\b/', fn ($m) => strtolower($m[0]), $label);
    }
}

if (! function_exists('money')) {
    /**
     * Formats an amount in a currency, with or without the intl extension.
     */
    function money(float|int|null $amount, ?string $currency = 'USD'): string
    {
        $currency = strtoupper($currency ?: 'USD');
        if (class_exists(NumberFormatter::class)) {
            return (new NumberFormatter('en', NumberFormatter::CURRENCY))->formatCurrency((float) $amount, $currency);
        }
        $symbols = ['USD' => '$', 'CAD' => 'CA$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹', 'JPY' => '¥', 'CNY' => 'CN¥', 'BRL' => 'R$', 'MXN' => 'MX$', 'SEK' => 'SEK ', 'CHF' => 'CHF '];
        $decimals = in_array($currency, ['JPY', 'KRW'], true) ? 0 : 2;

        return ($amount < 0 ? '-' : '').($symbols[$currency] ?? $currency.' ').number_format(abs((float) $amount), $decimals);
    }
}

if (! function_exists('page')) {
    /**
     * A React admin page: resources/app/pages/{component}.jsx with these props.
     */
    function page(string $component, array $props = [], int $status = 200): App\Support\Spa\Page
    {
        return new App\Support\Spa\Page($component, $props, $status);
    }
}
