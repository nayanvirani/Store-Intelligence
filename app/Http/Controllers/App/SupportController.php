<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Support\Attachment;
use App\Models\Support\Ticket;
use App\Services\Support\SupportDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Support\Spa\Page;
use Symfony\Component\HttpFoundation\Response;

/**
 * Support (section 34) for merchants: open tickets, follow the conversation, reply and close.
 */
class SupportController extends Controller
{
    public function __construct(private readonly SupportDesk $desk) {}

    public function index(Request $request, Store $store): Page
    {
        return page('support/index', [
            'tickets' => Ticket::where('store_id', $store->id)->latest('updated_at')->get()->map(fn (Ticket $t) => [
                'id' => $t->id, 'reference' => $t->reference(), 'subject' => $t->subject, 'status' => $t->status,
                'category' => Ticket::CATEGORIES[$t->category] ?? $t->category, 'updated_at' => $t->updated_at,
            ]),
            'categories' => Ticket::CATEGORIES,
            'priorities' => Ticket::PRIORITIES,
            'statuses' => Ticket::STATUSES,
            'helpUrl' => \App\Support\Legal::url('support'),
            'docsUrl' => route('site.contact'),
        ]);
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'subject' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:10000'],
            'category' => ['required', 'in:'.implode(',', array_keys(Ticket::CATEGORIES))], 'priority' => ['nullable', 'in:'.implode(',', array_keys(Ticket::PRIORITIES))],
        ] + SupportDesk::ATTACHMENT_RULES, ['attachments.*.mimes' => 'Attach images, PDFs, text or CSV files.', 'attachments.*.max' => 'Each attachment can be up to 4 MB.']);
        $validator->validate();
        $ticket = $this->desk->open($store, $request->attributes->get('storeUser'), $validator->validated(), (array) $request->file('attachments', []));

        return redirect()->to(app_route('app.support.show', ['ticket' => $ticket->id, 'notice' => 'ticket_created']));
    }

    public function show(Request $request, Store $store, int $ticket): Page
    {
        $ticket = $this->find($store, $ticket);

        return page('support/show', [
            'ticket' => [
                'id' => $ticket->id, 'reference' => $ticket->reference(), 'subject' => $ticket->subject, 'status' => $ticket->status,
                'status_label' => Ticket::STATUSES[$ticket->status], 'category' => Ticket::CATEGORIES[$ticket->category] ?? $ticket->category,
                'priority' => Ticket::PRIORITIES[$ticket->priority] ?? $ticket->priority, 'created_at' => $ticket->created_at, 'resolution' => $ticket->resolution,
            ],
            'messages' => $ticket->messages()->where('internal', false)->with('attachments')->get()->map(fn ($m) => [
                'id' => $m->id, 'team' => $m->author === 'team', 'created_at' => $m->created_at, 'body' => $m->body,
                'author' => $m->author === 'team' ? 'Store Intelligence team'.($m->author_name ? ' · '.$m->author_name : '') : ($m->author_name ?: 'You'),
                'attachments' => $m->attachments->map(fn ($a) => ['id' => $a->id, 'filename' => $a->filename, 'kb' => (int) round($a->size / 1024)]),
            ]),
        ]);
    }

    public function reply(Request $request, Store $store, int $ticket): RedirectResponse
    {
        $ticket = $this->find($store, $ticket);
        $validator = Validator::make($request->all(), ['body' => ['required', 'string', 'max:10000']] + SupportDesk::ATTACHMENT_RULES);
        $validator->validate();
        $this->desk->reply($ticket, 'merchant', $request->input('body'), (array) $request->file('attachments', []), false, $request->attributes->get('storeUser'));

        return redirect()->to(app_route('app.support.show', ['ticket' => $ticket->id, 'notice' => 'reply_sent']));
    }

    public function close(Request $request, Store $store, int $ticket): RedirectResponse
    {
        $ticket = $this->find($store, $ticket);
        $ticket->forceFill(['status' => $ticket->status === 'closed' ? 'open' : 'closed', 'resolved_at' => $ticket->status === 'closed' ? null : ($ticket->resolved_at ?? now())])->save();

        return redirect()->to(app_route('app.support.show', ['ticket' => $ticket->id, 'notice' => 'saved']));
    }

    public function attachment(Request $request, Store $store, int $attachment): Response
    {
        $file = Attachment::with('message.ticket')->findOrFail($attachment);
        abort_unless($file->message->ticket->store_id === $store->id && ! $file->message->internal, 404);

        return self::download($file);
    }

    public static function download(Attachment $file): Response
    {
        $raw = $file->getRawOriginal('content');
        $content = base64_decode(is_resource($raw) ? stream_get_contents($raw) : (string) $raw);

        return response($content, 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => (str_starts_with($file->mime, 'image/') || $file->mime === 'application/pdf' ? 'inline' : 'attachment').'; filename="'.addslashes($file->filename).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function find(Store $store, int $id): Ticket
    {
        return Ticket::where('store_id', $store->id)->findOrFail($id);
    }
}
