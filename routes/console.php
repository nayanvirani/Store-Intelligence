<?php

use App\Services\Sync\SyncManager;
use Illuminate\Support\Facades\Schedule;

/*
| Run by the container's scheduler process: php artisan schedule:work
*/

// Orders: catch anything a webhook missed (the last finished run, with an hour of overlap).
Schedule::call(fn () => app(SyncManager::class)->scheduled('orders'))->name('sync-orders')->hourly()->withoutOverlapping(30);
// Catalog: costs, prices and stock, once a day.
Schedule::call(fn () => app(SyncManager::class)->scheduled('products'))->name('sync-products')->dailyAt('02:30')->withoutOverlapping(60);
// Plan features and limits apply when they change (e.g. a complimentary plan ends).
Schedule::command('si:apply-entitlements')->everyFiveMinutes()->withoutOverlapping(15);
// Housekeeping: webhook receipts and finished sync runs older than 30 days, failed jobs older than 14.
Schedule::call(function () {
    \App\Models\WebhookReceipt::where('created_at', '<', now()->subDays(30))->delete();
    \App\Models\SyncRun::whereIn('status', ['done', 'failed'])->where('created_at', '<', now()->subDays(30))->delete();
})->name('prune')->dailyAt('03:15');
Schedule::command('queue:prune-failed --hours=336')->dailyAt('03:30');
