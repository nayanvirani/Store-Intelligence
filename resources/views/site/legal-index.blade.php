@extends('layouts.site')
@section('title', 'Legal | Store Intelligence')
@section('content')
<section class="section" style="padding-top:64px">
    <div class="wrap">
        <span class="eyebrow">Legal</span>
        <h1 style="font-size:clamp(30px,4vw,44px)">Policies</h1>
        <div class="grid c2" style="margin-top:24px">
            @foreach ($pages as $p)
                <a class="card" style="text-decoration:none;color:inherit" href="{{ \App\Support\Legal::url($p['slug']) }}"><h3>{{ $p['title'] }}</h3><p class="small">{{ preg_replace('/\{\{\s*app_name\s*\}\}/', \App\Support\Legal::details()['trading_name'] ?: 'Store Intelligence', (string) $p['summary']) }}</p></a>
            @endforeach
        </div>
    </div>
</section>
@endsection
