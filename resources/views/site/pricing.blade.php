@extends('layouts.site')
@section('title', 'Pricing | Store Intelligence')
@section('content')
<section class="section" style="padding-top:72px">
    <div class="wrap">
        <span class="eyebrow">Pricing</span>
        <h1 style="font-size:clamp(32px,4vw,48px)">{{ $pricing['headline'] }}</h1>
        <p class="lead">{{ $pricing['lead'] }}</p>
        @include('site._plans')
    </div>
</section>
@if ($compare)
<section class="section alt">
    <div class="wrap">
        <h2>{{ $pricing['compare_title'] }}</h2>
        <div style="overflow-x:auto;margin-top:20px">
            <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;font-size:14px;border-collapse:separate;border-spacing:0">
                <thead><tr style="text-align:left;background:#FAFBFC;color:var(--muted)"><th style="padding:12px">Feature</th>@foreach ($plans as $p)<th style="padding:12px">{{ $p['name'] }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($compare as $group)
                        <tr><td colspan="{{ count($plans) + 1 }}" style="padding:12px;font-weight:700;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);background:#F6F8FA">{{ $group['group'] }}</td></tr>
                        @foreach ($group['rows'] as $row)
                            <tr style="border-top:1px solid var(--line)">
                                <td style="padding:10px 12px" title="{{ $row['help'] }}">{{ $row['label'] }}</td>
                                @foreach ($plans as $p)
                                    @php($v = $row['values'][$p['key']] ?? false)
                                    <td style="padding:10px 12px">{!! $v === true ? '<span style="color:var(--brand);font-weight:700">✓</span>' : ($v === false ? '<span class="muted">—</span>' : e($v)) !!}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>
@endif
@endsection
