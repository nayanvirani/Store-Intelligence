{{--
    Shopify's app menu shows no items: navigation lives in the app's own sidebar (sections with
    nested pages). The rel="home" link only sets where the app name in Shopify's menu goes.
--}}
@php($navStore = request()->attributes->get('store'))
<s-app-nav>
    <s-link href="{{ $navStore?->hasPlanAccess() ? app_route('app.dashboard') : app_route('app.settings.billing') }}" rel="home">Home</s-link>
</s-app-nav>
