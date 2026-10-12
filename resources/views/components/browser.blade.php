@props(['url' => 'yourstore.com', 'glow' => true, 'flush' => false])
<div {{ $attributes->merge(['class' => 'visual']) }}>
    @if ($glow)<div class="glow" aria-hidden="true"></div>@endif
    <div class="browser" role="img" aria-label="{{ $attributes->get('aria-label', 'Product preview') }}">
        <div class="chrome"><i></i><i></i><i></i><div class="url"><x-icon name="lock"/>{{ $url }}</div></div>
        <div class="{{ $flush ? '' : 'page' }}">{{ $slot }}</div>
    </div>
    {{ $chips ?? '' }}
</div>
