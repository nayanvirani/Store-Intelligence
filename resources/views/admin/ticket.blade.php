@extends('admin.layout')
@section('title', $ticket->reference())
@section('content')
@php use App\Models\Support\Ticket; @endphp
<p class="ad-crumbs"><a href="{{ route('admin.tickets') }}">Support desk</a> / {{ $ticket->reference() }}</p>
<div class="ad-head"><div><h1>{{ $ticket->subject }}</h1><p>{{ $ticket->reference() }} · <a href="{{ route('admin.store', $ticket->store_id) }}">{{ $ticket->store->shop_domain }}</a> · <span class="ad-badge">{{ Ticket::STATUSES[$ticket->status] ?? $ticket->status }}</span></p></div></div>
<div class="ad-grid-2">
    <section class="ad-card ad-thread">
        <h2>Conversation</h2>
        @foreach ($ticket->messages as $m)
            <div class="ad-msg {{ $m->author }} {{ $m->internal ? 'internal' : '' }}">
                <div class="ad-muted"><b>{{ $m->internal ? 'Internal note' : ($m->author === 'team' ? 'Team' : 'Merchant') }}</b> · {{ $m->author_name }} · {{ $m->created_at->toDayDateTimeString() }}</div>
                <div>{!! nl2br(e($m->body)) !!}</div>
                @foreach ($m->attachments as $a)<div><a href="{{ route('admin.attachment', $a->id) }}" target="_blank">📎 {{ $a->filename }}</a> <span class="ad-muted">{{ round($a->size / 1024) }} KB</span></div>@endforeach
            </div>
        @endforeach
        <form method="POST" action="{{ route('admin.ticket.reply', $ticket->id) }}" enctype="multipart/form-data" class="ad-form">
            @csrf
            <label>Reply<textarea name="body" rows="5" required></textarea></label>
            <input type="file" name="attachments[]" multiple>
            <label class="ad-check"><input type="checkbox" name="internal" value="1"> Internal note (the merchant doesn't see it)</label>
            <button class="ad-btn primary" type="submit">Send</button>
        </form>
    </section>
    <section class="ad-card">
        <h2>Ticket</h2>
        <form method="POST" action="{{ route('admin.ticket.update', $ticket->id) }}" class="ad-form">
            @csrf
            <label>Status<select name="status">@foreach (Ticket::STATUSES as $k => $l)<option value="{{ $k }}" @selected($ticket->status === $k)>{{ $l }}</option>@endforeach</select></label>
            <label>Priority<select name="priority">@foreach (Ticket::PRIORITIES as $k => $l)<option value="{{ $k }}" @selected($ticket->priority === $k)>{{ $l }}</option>@endforeach</select></label>
            <label>Assigned to<select name="assigned_to"><option value="">Nobody</option>@foreach ($team as $u)<option value="{{ $u->id }}" @selected($ticket->assigned_to === $u->id)>{{ $u->name }} ({{ $u->email }})</option>@endforeach</select></label>
            <label>Resolution (shown to the merchant)<textarea name="resolution" rows="3">{{ $ticket->resolution }}</textarea></label>
            <button class="ad-btn primary" type="submit">Update ticket</button>
        </form>
        <h2 style="margin-top:18px">Store</h2>
        <p><a href="{{ route('admin.store', $ticket->store_id) }}">{{ $ticket->store->shop_domain }}</a><br><span class="ad-muted">Opened by {{ $ticket->requester?->email ?? 'a staff member' }} · {{ Ticket::CATEGORIES[$ticket->category] ?? $ticket->category }}</span></p>
        <h2 style="margin-top:18px">Diagnostics at opening</h2>
        <pre class="ad-pre">{{ json_encode($ticket->diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </section>
</div>
@endsection
