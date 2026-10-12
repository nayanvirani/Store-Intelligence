{{-- Shopify's app menu (one level; Settings has its own sidebar). Without a plan, only Billing. --}}
@php($navStore = request()->attributes->get('store'))
@if (! $navStore?->hasPlanAccess())
<s-app-nav>
    <s-link href="{{ app_route('app.settings.billing') }}" rel="home">Choose a plan</s-link>
</s-app-nav>
@else
<s-app-nav>
    <s-link href="{{ app_route('app.dashboard') }}" rel="home">Home</s-link>
    @unless ($navStore->onboarding_completed_at)
        <s-link href="{{ app_route('app.onboarding') }}">Get started</s-link>
    @endunless
    <s-link href="{{ app_route('app.products.index') }}">Products &amp; costs</s-link>
    <s-link href="{{ app_route('app.settings.general') }}">Settings</s-link>
    <s-link href="{{ app_route('app.support.index') }}">Support</s-link>
</s-app-nav>
@endif
