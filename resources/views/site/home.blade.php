@extends('layouts.site')
@section('content')
<section class="section" style="padding-top:84px;background:radial-gradient(1200px 400px at 70% -10%, #ccfbf1 0, transparent 60%), radial-gradient(900px 400px at 0% 0%, #dbeafe 0, transparent 55%)">
    <div class="wrap">
        <span class="eyebrow">Profit · Protection · Marketing</span>
        <h1>See your real profit.<br>Catch problems before they cost you.</h1>
        <p class="lead">Store Intelligence shows what your Shopify store really earns after product costs, fees, shipping and ad spend — and alerts you the moment something starts leaking money.</p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:26px">
            <a class="btn primary" href="{{ config('shopify.install_url') }}">Install free on Shopify</a>
            <a class="btn" href="{{ route('site.pricing') }}">See pricing</a>
        </div>
        <p class="muted small" style="margin-top:14px">Free plan available. Read-only access to your store — we never change your products, prices or theme.</p>
    </div>
</section>

<section class="section" id="features">
    <div class="wrap">
        <span class="eyebrow">What you get</span>
        <h2>Everything you need to grow profitably</h2>
        <div class="grid c3" style="margin-top:28px">
            @foreach ([
                ['Real Profit Dashboard', 'Net sales minus product costs, payment fees, shipping and ad spend — every day, automatically.'],
                ['Break-even ROAS', 'Know the ROAS each product needs to make money, and which campaigns are below it.'],
                ['Money-leak alerts', 'Price mistakes, best sellers running out, ads sending traffic to sold-out or broken pages.'],
                ['Store Health Score', 'One simple score that rises as you fix issues — with the next best fix highlighted.'],
                ['AI social calendar', 'A full month of posts planned and written for you. Approve the month in one click.'],
                ['Monthly Business Report', 'Profit, ads, social and top products — delivered on the 1st of every month.'],
            ] as [$title, $text])
                <div class="card"><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
            @endforeach
        </div>
    </div>
</section>

<section class="section alt">
    <div class="wrap grid c2" style="align-items:center">
        <div>
            <span class="eyebrow">Honest numbers</span>
            <h2>Every number shows how it's calculated</h2>
            <p class="lead" style="font-size:17px">Profit is labelled by level — gross, contribution, after ads. Missing costs are flagged, never hidden. Unavailable data shows as unavailable, not zero.</p>
        </div>
        <div class="card">
            @foreach ([['Gross sales', '$48,200'], ['− Discounts & returns', '$3,950'], ['− Product costs', '$17,800'], ['− Fees & shipping', '$4,610'], ['− Ad spend', '$9,200'], ['= Profit after ads', '$12,640']] as [$k, $v])
                <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--line);{{ $loop->last ? 'border:0;font-weight:800;color:#047857' : '' }}"><span>{{ $k }}</span><span>{{ $v }}</span></div>
            @endforeach
            <p class="small" style="margin-top:10px">Example figures.</p>
        </div>
    </div>
</section>

@if ($plans)
<section class="section">
    <div class="wrap">
        <span class="eyebrow">Pricing</span>
        <h2>{{ $pricing['headline'] }}</h2>
        <p class="lead" style="font-size:17px">{{ $pricing['lead'] }}</p>
        @include('site._plans')
    </div>
</section>
@endif
@endsection
