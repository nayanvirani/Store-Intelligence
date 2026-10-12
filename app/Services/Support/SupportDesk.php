<?php

namespace App\Services\Support;

use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Support\Message;
use App\Models\Support\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Support tickets: merchants open them in the app (with their store's details attached), the
 * Store Intelligence team answers in the Internal Admin. Team-only notes never reach the merchant.
 */
class SupportDesk
{
    /** Attachment rules shared by both sides. */
    public const ATTACHMENT_RULES = ['attachments' => ['array', 'max:3'], 'attachments.*' => ['file', 'max:4096', 'mimes:png,jpg,jpeg,gif,webp,pdf,txt,csv']];

    public function open(Store $store, ?StoreUser $user, array $data, array $files = []): Ticket
    {
        return DB::transaction(function () use ($store, $user, $data, $files) {
            $ticket = Ticket::create([
                'store_id' => $store->id, 'store_user_id' => $user?->id,
                'subject' => mb_substr(trim(strip_tags($data['subject'])), 0, 160),
                'category' => array_key_exists($data['category'] ?? '', Ticket::CATEGORIES) ? $data['category'] : 'technical',
                'priority' => array_key_exists($data['priority'] ?? '', Ticket::PRIORITIES) ? $data['priority'] : 'normal',
                'status' => 'open', 'diagnostics' => $this->diagnostics($store),
            ]);
            $this->reply($ticket, 'merchant', $data['body'], $files, false, $user);

            return $ticket->fresh();
        });
    }

    public function reply(Ticket $ticket, string $author, string $body, array $files = [], bool $internal = false, StoreUser|User|null $by = null): Message
    {
        $message = $ticket->messages()->create([
            'author' => $author, 'internal' => $internal && $author === 'team',
            'author_name' => $by ? trim(($by->first_name ?? $by->name ?? '').' '.($by->last_name ?? '')) ?: ($by->email ?? null) : null,
            'store_user_id' => $by instanceof StoreUser ? $by->id : null,
            'user_id' => $by instanceof User ? $by->id : null,
            'body' => mb_substr(trim($body), 0, 10000),
        ]);
        foreach (array_slice($files, 0, 3) as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $message->attachments()->create([
                    'filename' => mb_substr(preg_replace('/[^\w.\- ]+/u', '_', $file->getClientOriginalName()), 0, 160),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(),
                    'content' => base64_encode($file->get()),
                ]);
            }
        }
        if (! $message->internal) {
            // A merchant reply reopens the ticket; a team reply waits for the merchant.
            $ticket->forceFill([
                'last_reply_at' => now(), 'last_reply_by' => $author,
                'status' => $author === 'merchant' ? 'open' : ($ticket->status === 'resolved' || $ticket->status === 'closed' ? $ticket->status : 'pending'),
            ])->save();
        }

        return $message;
    }

    /** Store details attached to a ticket so the team doesn't have to ask. */
    public function diagnostics(Store $store): array
    {
        return [
            'shop' => $store->shop_domain, 'plan' => $store->effectivePlan(), 'shopify_plan' => $store->shopify_plan,
            'currency' => $store->currency, 'timezone' => $store->timezone, 'installed_at' => $store->installed_at?->toIso8601String(),
            'capabilities' => $store->capabilities, 'missing_scopes' => $store->missingScopes(),
            'data' => ['products' => $store->products()->count(), 'orders' => $store->orders()->count(), 'cost_rules' => $store->costRules()->count()],
            'sync' => collect(app(\App\Services\Sync\SyncManager::class)->status($store))->map(fn ($s) => ['status' => $s['status'], 'last_success_at' => $s['last_success_at']?->toIso8601String()])->all(),
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
