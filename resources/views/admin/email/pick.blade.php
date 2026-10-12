@extends('admin.layout')
@section('title', 'Add email provider')
@section('content')
<p class="ad-crumbs"><a href="{{ route('admin.email') }}">Email providers</a> / Add</p>
<div class="ad-head"><div><h1>Add an email provider</h1><p>Pick the service you created an API key with. You can add as many as you like.</p></div></div>
<div class="ad-pick">
    @foreach (\App\Services\Mail\Drivers::ALL as $key => $d)
        <a class="ad-pick-card" href="{{ route('admin.email.create', ['driver' => $key]) }}">
            <b>{{ $d['label'] }}</b>
            <span>{{ $d['note'] }}</span>
        </a>
    @endforeach
</div>
@endsection
