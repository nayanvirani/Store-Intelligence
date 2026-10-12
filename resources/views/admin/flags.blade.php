@extends('admin.layout')
@section('title', 'Feature flags')
@section('content')
<div class="ad-head"><div><h1>Feature flags</h1><p>Turn unreleased features on for everyone or for listed stores. To give one store a paid module, use its Plan & access tab.</p></div></div>
<section class="ad-card flush"><div class="ad-scroll">
<table class="ad-table">
    <thead><tr><th>Key</th><th>Description</th><th>Everyone</th><th>Only these stores</th><th></th></tr></thead>
    <tbody>
        @forelse ($flags as $f)
            <tr>
                <td><code>{{ $f->key }}</code></td><td>{{ $f->description }}</td><td>{!! $f->enabled ? '<span class="ad-badge ok">On</span>' : '<span class="ad-badge">Off</span>' !!}</td>
                <td>{{ \App\Models\Store::whereIn('id', $f->store_ids ?? [])->pluck('shop_domain')->implode(', ') ?: '—' }}</td>
                <td><form method="POST" action="{{ route('admin.flags.delete', $f->id) }}" onsubmit="return confirm('Delete this flag?')">@csrf<button class="ad-btn small danger" type="submit">Delete</button></form></td>
            </tr>
        @empty <tr><td colspan="5" class="ad-empty">No flags yet.</td></tr> @endforelse
    </tbody>
</table>
</div></section>
<section class="ad-card">
    <h2>Add or update a flag</h2>
    <form method="POST" action="{{ route('admin.flags.save') }}" class="ad-form">
        @csrf
        <label>Key<input name="key" required pattern="[a-z0-9_.\-]{2,60}" placeholder="email_sending"></label>
        <label>Description<input name="description" maxlength="300"></label>
        <label>Only these stores (shop domains, one per line or comma-separated)<textarea name="store_domains" rows="3"></textarea></label>
        <label class="ad-check"><input type="checkbox" name="enabled" value="1"> On for every store</label>
        <button class="ad-btn primary" type="submit">Save flag</button>
    </form>
</section>
@endsection
