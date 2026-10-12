@extends('admin.layout')
@section('title', 'Sign in')
@section('content')
<form method="POST" action="{{ route('admin.login') }}" class="ad-login">
    @csrf
    <div class="ad-brand" style="color:var(--ink);padding:0"><img src="/brand/si-icon.svg" alt="" width="30" height="30"><span>Store Intelligence<small style="color:var(--muted)">Internal admin</small></span></div>
    <p class="ad-muted">For the Store Intelligence team only.</p>
    <label>Email<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <label class="ad-check"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
    @error('email')<p class="ad-error">{{ $message }}</p>@enderror
    <button type="submit" class="ad-btn primary">Sign in</button>
</form>
@endsection
