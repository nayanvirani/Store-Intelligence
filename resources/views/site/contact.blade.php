@extends('layouts.site')
@section('title', 'Contact | Store Intelligence')
@section('content')
<section class="section" style="padding-top:72px">
    <div class="wrap grid c2" style="align-items:start">
        <div>
            <span class="eyebrow">Contact</span>
            <h1 style="font-size:clamp(32px,4vw,46px)">Talk to us</h1>
            <p class="lead" style="font-size:17px">Questions about Store Intelligence, pricing or partnerships? Send a message and we'll reply by email. Merchants already using the app get the fastest answer from <b>Support</b> inside the app.</p>
        </div>
        <div class="card">
            @if (session('contact_sent'))<div class="alert ok" role="status">Thanks — your message was sent. We usually reply within one business day.</div>@endif
            @if (session('contact_failed'))<div class="alert bad" role="alert">We couldn't send your message. Please try again in a moment.</div>@endif
            <form method="POST" action="{{ route('site.contact.submit') }}" novalidate style="display:grid;gap:16px">
                @csrf
                <label class="fld">Name<input name="name" value="{{ old('name') }}" required autocomplete="name">@error('name')<span class="err">{{ $message }}</span>@enderror</label>
                <label class="fld">Email<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">@error('email')<span class="err">{{ $message }}</span>@enderror</label>
                <label class="fld">Store URL (optional)<input name="store_url" value="{{ old('store_url') }}" placeholder="your-store.myshopify.com"></label>
                <label class="fld">Topic
                    <select name="topic" required>@foreach (['Sales', 'Support', 'Partnership', 'Other'] as $t)<option value="{{ $t }}" @selected(old('topic') === $t)>{{ $t }}</option>@endforeach</select>
                </label>
                <label class="fld">Message<textarea name="message" required>{{ old('message') }}</textarea>@error('message')<span class="err">{{ $message }}</span>@enderror</label>
                <div style="position:absolute;left:-9999px" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
                <button class="btn primary" type="submit">Send message</button>
                <span class="muted small">We use your details only to reply. See our <a href="{{ route('site.privacy') }}">Privacy Policy</a>.</span>
            </form>
        </div>
    </div>
</section>
@endsection
