@extends('admin.layout')
@section('title', 'Platform settings')
@section('content')
<div class="ad-head"><div><h1>Platform settings</h1><p>Usage warnings, test stores and the website's Shopify links.</p></div></div>
<form method="POST" action="{{ route('admin.settings.update') }}" class="ad-form">
    @csrf
    <section class="ad-card">
        <h2>Shopify App Store</h2>
        @php($install = config('shopify.install_url'))
        <p class="ad-muted" style="margin-top:-4px">"Install app" buttons now open <b>{{ $install === '/contact' ? 'the contact page' : $install }}</b>{{ $links['install_url'] ? '' : ', because no App Store link is set yet' }}.</p>
        <div class="ad-fields">
            <label>{{ $fields['install_url'][1] }}<input type="url" name="install_url" value="{{ old('install_url', $links['install_url']) }}" placeholder="https://apps.shopify.com/store-intelligence" maxlength="300"><small>{{ $fields['install_url'][3] }}</small></label>
            <label>{{ $fields['sign_in_url'][1] }}<input type="url" name="sign_in_url" value="{{ old('sign_in_url', $links['sign_in_url']) }}" placeholder="https://admin.shopify.com" maxlength="300"><small>{{ $fields['sign_in_url'][3] }}</small></label>
        </div>
        @error('install_url')<p class="ad-note warn" style="margin-top:12px">{{ $message }}</p>@enderror
        @error('sign_in_url')<p class="ad-note warn" style="margin-top:12px">{{ $message }}</p>@enderror
    </section>
    <section class="ad-card">
        <h2>Usage limits</h2>
        <div class="ad-fields">
            <label>{{ $fields['warn_at'][1] }}<span class="ad-input-prefix"><input type="number" name="warn_at" min="10" max="100" value="{{ old('warn_at', (int) round($values['warn_at'] * 100)) }}" style="border-radius:9px 0 0 9px"><span style="border-radius:0 9px 9px 0;border-left:0;border-right:1px solid #cfd1dc">%</span></span><small>{{ $fields['warn_at'][3] }}</small></label>
        </div>
    </section>
    <section class="ad-card">
        <h2>Test stores</h2>
        <div class="ad-fields">
            <label>{{ $fields['test_shops'][1] }}<textarea name="test_shops" rows="4" placeholder="vantora-plus.myshopify.com">{{ old('test_shops', implode("\n", (array) $values['test_shops'])) }}</textarea><small>{{ $fields['test_shops'][3] }}</small></label>
            <label>{{ $fields['test_shop_plan'][1] }}
                <select name="test_shop_plan">@foreach ($plans as $k => $name)<option value="{{ $k }}" @selected($values['test_shop_plan'] === $k)>{{ $name }}</option>@endforeach</select>
            </label>
        </div>
    </section>
    <div class="ad-savebar"><button class="ad-btn primary" type="submit">Save settings</button></div>
</form>
@endsection
