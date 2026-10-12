@extends('admin.layout')
@section('title', 'Your account')
@section('content')
<div class="ad-head"><div><h1>Your account</h1><p>{{ auth()->user()->email }} · {{ \App\Support\AdminRoles::label(auth()->user()->admin_role) }}</p></div></div>
<section class="ad-card" style="max-width:480px">
    <h2>Change password</h2>
    <form method="POST" action="{{ route('admin.account.password') }}" class="ad-form">
        @csrf
        <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
        <label>New password<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
        <p class="ad-muted">At least 12 characters, with letters and numbers. Other signed-in sessions are signed out.</p>
        <button class="ad-btn primary" type="submit">Change password</button>
    </form>
</section>
@endsection
