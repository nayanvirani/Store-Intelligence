@extends('admin.layout')
@section('title', $plan->exists ? $plan->name : 'New plan')
@section('content')
<p class="ad-crumbs"><a href="{{ route('admin.plans') }}">Plans & modules</a> / {{ $plan->exists ? $plan->name : 'New plan' }}</p>
<div class="ad-head"><div><h1>{{ $plan->exists ? 'Edit '.$plan->name : 'New plan' }}</h1><p>Shopify charges the price set in the Partner Dashboard (Managed Pricing); keep them the same.</p></div></div>

<form method="POST" action="{{ $plan->exists ? route('admin.plans.update', $plan->id) : route('admin.plans.store') }}" class="ad-form">
    @csrf
    <section class="ad-card">
        <h2>Plan</h2>
        <div class="ad-fields">
            @unless ($plan->exists)
                <label>Key<input name="key" value="{{ old('key') }}" required pattern="[a-z0-9_]{2,40}" placeholder="pro"><small>Lowercase letters, numbers and _. Can't change later.</small></label>
            @endunless
            <label>Name<input name="name" value="{{ old('name', $plan->name) }}" required maxlength="80"></label>
            <label>Name in Shopify Managed Pricing<input name="shopify_name" value="{{ old('shopify_name', $plan->shopify_name) }}" required maxlength="120"><small>Must match exactly, so subscriptions map to this plan.</small></label>
            <label>Price per month (USD)<span class="ad-input-prefix"><span>$</span><input type="number" name="price" step="0.01" min="0" value="{{ old('price', $plan->price ?? 0) }}" required></span></label>
            <label>Trial days<input type="number" name="trial_days" min="0" max="365" value="{{ old('trial_days', $plan->trial_days ?? 0) }}"><small>Shown to merchants; set the same in Shopify.</small></label>
            <label>Badge (optional)<input name="badge" value="{{ old('badge', $plan->badge) }}" maxlength="40" placeholder="Most Popular"><small>Highlights the plan on pricing pages.</small></label>
            <label>Support level<input name="support_label" value="{{ old('support_label', $plan->support_label) }}" maxlength="80" placeholder="Standard"><small>Shown in the plan comparison.</small></label>
            <label>Order on pricing pages<input type="number" name="position" min="0" max="1000" value="{{ old('position', $plan->position) }}"></label>
        </div>
        <div class="ad-fields" style="margin-top:14px">
            <label class="ad-check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active))><span>Active<small>Off: no longer offered. Stores already on it keep it.</small></span></label>
            <label class="ad-check"><input type="checkbox" name="is_public" value="1" @checked(old('is_public', $plan->is_public))><span>Shown on pricing pages<small>Hidden plans can still be given to stores as a complimentary plan.</small></span></label>
        </div>
    </section>

    <section class="ad-card">
        <h2>Features</h2>
        @foreach (\App\Support\Modules::grouped() as $group => $modules)
            <p class="ad-group-title">{{ $group }}</p>
            <div class="ad-fields">
                @foreach ($modules as $key => [$label, , $help, $parent])
                    <label class="ad-check {{ $parent ? 'ad-child' : '' }}"><input type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key, old('modules', $plan->modules ?? []), true))><span>{{ $label }}<small>{{ $help }}</small></span></label>
                @endforeach
            </div>
        @endforeach
    </section>

    <section class="ad-card">
        <h2>Usage limits</h2>
        <p class="ad-muted">Empty = unlimited, 0 = none. Live-offer limits count published offers of that kind; when one is reached, merchants can't publish another until they pause one or upgrade.</p>
        @foreach (\App\Services\Usage::GROUPS as $limitGroup => $meters)
            <p class="ad-group-title">{{ $limitGroup }}</p>
            <div class="ad-fields">
                @foreach ($meters as $meter)
                    <label>{{ \App\Services\Usage::METERS[$meter] }}<input type="number" min="0" name="limits[{{ $meter }}]" value="{{ old('limits.'.$meter, ($plan->limits ?? [])[$meter] ?? '') }}" placeholder="Unlimited">@if (isset(\App\Services\Usage::HELP[$meter]))<small>{{ \App\Services\Usage::HELP[$meter] }}</small>@endif</label>
                @endforeach
            </div>
        @endforeach
    </section>

    <section class="ad-card">
        <h2>Pricing page</h2>
        <div class="ad-fields">
            <label>Tagline (who it's for)<input name="description" maxlength="300" value="{{ old('description', $plan->description) }}"></label>
        </div>
        <label style="margin-top:14px">What's included (one line each)<textarea name="features" rows="6">{{ old('features', implode("\n", $plan->features ?? [])) }}</textarea><small>Shown on the in-app billing page and the public pricing page. Put a limit in a line so it always matches: <code>{bundles|bundle}</code> shows "1 bundle", "3 bundles" or "Unlimited bundles"; <code>{automation_executions}</code> shows just the number. A line with a limit of 0 is hidden. Limits: {{ implode(', ', array_keys(\App\Services\Usage::METERS)) }}.</small></label>
    </section>

    <div class="ad-savebar">
        <a class="ad-btn" href="{{ route('admin.plans') }}">Cancel</a>
        <button class="ad-btn primary" type="submit">{{ $plan->exists ? 'Save plan' : 'Create plan' }}</button>
    </div>
</form>
@endsection
