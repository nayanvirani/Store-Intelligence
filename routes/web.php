<?php

use App\Http\Controllers\App\ActivityController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\CostRulesController;
use App\Http\Controllers\App\GeneralSettingsController;
use App\Http\Controllers\App\HomeController;
use App\Http\Controllers\App\IntegrationsController;
use App\Http\Controllers\App\OnboardingController;
use App\Http\Controllers\App\ProductsController;
use App\Http\Controllers\App\StoreSettingsController;
use App\Http\Controllers\App\UsersController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Shopify's webhooks carry no session: no cookies in the response, no session row written.
$stateless = [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
];

Route::get('/robots.txt', [SiteController::class, 'robots'])->name('site.robots');

// Public website: landing, pricing, contact and the legal pages Shopify requires.
Route::controller(SiteController::class)->name('site.')->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/pricing', 'pricing')->name('pricing');
    Route::get('/contact', 'contact')->name('contact');
    Route::post('/contact', 'submitContact')->middleware('throttle:5,1')->name('contact.submit');
    Route::get('/privacy', 'privacy')->name('privacy');
    Route::get('/terms', 'terms')->name('terms');
    Route::get('/dpa', 'dpa')->name('dpa');
    Route::get('/legal', 'legalIndex')->name('legal.index');
    Route::get('/legal/{slug}', 'legal')->where('slug', '[a-z0-9-]+')->name('legal');
    Route::get('/sitemap.xml', 'sitemap')->name('sitemap');
});

// Merchant embedded app
Route::prefix('app')->middleware(['shopify.auth', 'spa'])->name('app.')->group(function () {
    // Updated Terms/policies: the merchant confirms they've reviewed them (recorded per store).
    Route::post('/legal/acknowledge', function (\Illuminate\Http\Request $request) {
        $store = $request->attributes->get('store');
        $user = $request->attributes->get('storeUser');
        $slugs = array_column(\App\Support\Legal::noticeFor($store)['pages'] ?? [], 'slug');
        if ($slugs) {
            \App\Support\Legal::acknowledge($store, 'in_app', trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')).($user?->email ? " <{$user->email}>" : ''), $slugs);
            \App\Models\AuditLog::record('store.legal_acknowledged', $store, ['pages' => $slugs, 'by' => $user?->email]);
        }

        return back();
    })->name('legal.acknowledge');

    // Billing stays reachable without a plan: it's where plans are chosen.
    Route::get('/settings/billing', [BillingController::class, 'index'])->name('settings.billing');

    Route::middleware('store.plan')->group(function () {
        Route::get('/', [HomeController::class, 'show'])->middleware('store.can:view_dashboard')->name('dashboard');

        Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
        Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->middleware('store.can:manage_settings')->name('onboarding.complete');

        // Products & costs (F15)
        Route::get('/products', [ProductsController::class, 'index'])->middleware('store.can:view_dashboard')->name('products.index');
        Route::post('/products/variants/{variant}/cost', [ProductsController::class, 'updateCost'])->whereNumber('variant')->middleware('store.can:manage_costs')->name('products.cost');

        // Support tickets
        Route::prefix('support')->name('support.')->controller(\App\Http\Controllers\App\SupportController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->middleware('throttle:10,60')->name('store');
            Route::get('/{ticket}', 'show')->whereNumber('ticket')->name('show');
            Route::post('/{ticket}/reply', 'reply')->whereNumber('ticket')->middleware('throttle:30,60')->name('reply');
            Route::post('/{ticket}/close', 'close')->whereNumber('ticket')->name('close');
            Route::get('/attachments/{attachment}', 'attachment')->whereNumber('attachment')->name('attachment');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', fn () => redirect()->to(app_route('app.settings.general')))->name('index');

            Route::get('/general', [GeneralSettingsController::class, 'show'])->name('general');
            Route::post('/general', [GeneralSettingsController::class, 'update'])->middleware('store.can:manage_settings')->name('general.update');

            // Costs & fees (F3)
            Route::get('/costs', [CostRulesController::class, 'index'])->name('costs');
            Route::middleware('store.can:manage_costs')->group(function () {
                Route::post('/costs', [CostRulesController::class, 'store'])->name('costs.store');
                Route::post('/costs/{rule}', [CostRulesController::class, 'update'])->whereNumber('rule')->name('costs.update');
                Route::post('/costs/{rule}/delete', [CostRulesController::class, 'destroy'])->whereNumber('rule')->name('costs.destroy');
            });

            Route::get('/store', [StoreSettingsController::class, 'show'])->name('store');
            Route::middleware('store.can:manage_settings')->group(function () {
                Route::post('/store/sync', [StoreSettingsController::class, 'syncNow'])->name('store.sync');
                Route::post('/store/recheck', [StoreSettingsController::class, 'recheck'])->name('store.recheck');
                Route::post('/store/reconnect', [StoreSettingsController::class, 'reconnect'])->name('store.reconnect');
            });

            Route::middleware('store.can:manage_users')->group(function () {
                Route::get('/users', [UsersController::class, 'index'])->name('users');
                Route::post('/users', [UsersController::class, 'invite'])->name('users.invite');
                Route::post('/users/{user}/role', [UsersController::class, 'updateRole'])->name('users.role');
                Route::post('/users/{user}/remove', [UsersController::class, 'remove'])->name('users.remove');
                Route::post('/users/{user}/restore', [UsersController::class, 'restore'])->name('users.restore');
            });

            Route::get('/activity', [ActivityController::class, 'index'])->middleware('store.can:view_activity')->name('activity');
            Route::get('/integrations', [IntegrationsController::class, 'integrations'])->name('integrations');
            Route::get('/privacy', [IntegrationsController::class, 'privacy'])->name('privacy');
            Route::middleware('store.can:manage_settings')->group(function () {
                Route::post('/privacy', [IntegrationsController::class, 'updatePrivacy'])->name('privacy.update');
                Route::get('/privacy/export', [IntegrationsController::class, 'export'])->name('privacy.export');
            });
        });
    });
});

// Shopify webhooks (app lifecycle, billing, catalog, inventory, orders, GDPR compliance)
Route::withoutMiddleware($stateless)->post('/webhooks/shopify', WebhookController::class)
    ->middleware('shopify.webhook')
    ->name('webhooks.shopify');

// Internal Admin: the Store Intelligence team's console, separate from the merchant app.
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [\App\Http\Controllers\Admin\AuthController::class, 'show'])->name('login');
    Route::post('/login', [\App\Http\Controllers\Admin\AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/logout', [\App\Http\Controllers\Admin\AuthController::class, 'logout'])->name('logout');

    Route::middleware(\App\Http\Middleware\EnsureAdmin::class)->group(function () {
        Route::controller(\App\Http\Controllers\Admin\AdminController::class)->group(function () {
            Route::get('/', 'home')->middleware('admin.can:dashboard')->name('home');
            Route::middleware('admin.can:stores')->group(function () {
                Route::get('/stores', 'stores')->name('stores');
                Route::get('/stores/{store}', 'store')->whereNumber('store')->name('store');
            });
            Route::get('/failures', 'failures')->middleware('admin.can:failures')->name('failures');
            Route::post('/failures/{id}/retry', 'retryJob')->whereNumber('id')->middleware('admin.can:failures')->name('failures.retry');
            Route::post('/failures/{id}/forget', 'forgetJob')->whereNumber('id')->middleware('admin.can:failures')->name('failures.forget');
            Route::get('/sync', 'sync')->middleware('admin.can:failures')->name('sync');
            Route::middleware('admin.can:flags')->group(function () {
                Route::get('/flags', 'flags')->name('flags');
                Route::post('/flags', 'saveFlag')->name('flags.save');
                Route::post('/flags/{flag}/delete', 'deleteFlag')->whereNumber('flag')->name('flags.delete');
            });
            Route::middleware('admin.can:support')->group(function () {
                Route::get('/tickets', 'tickets')->name('tickets');
                Route::get('/tickets/{ticket}', 'ticket')->whereNumber('ticket')->name('ticket');
                Route::post('/tickets/{ticket}/reply', 'replyTicket')->whereNumber('ticket')->name('ticket.reply');
                Route::post('/tickets/{ticket}', 'updateTicket')->whereNumber('ticket')->name('ticket.update');
                Route::get('/attachments/{attachment}', 'attachment')->whereNumber('attachment')->name('attachment');
                Route::get('/messages', 'messages')->name('messages');
                Route::post('/messages/{message}', 'updateMessage')->whereNumber('message')->name('messages.update');
            });
            Route::get('/audit', 'audit')->middleware('admin.can:audit')->name('audit');
        });

        // A store's plan, modules and limits, and support actions on it.
        Route::middleware('admin.can:stores.manage')->controller(\App\Http\Controllers\Admin\StoreAccessController::class)->group(function () {
            Route::post('/stores/{store}/access', 'save')->whereNumber('store')->name('store.access');
            Route::post('/stores/{store}/actions/{action}', 'action')->whereNumber('store')->whereIn('action', ['sync-billing', 'apply-access', 'refresh-store', 'sync-products', 'sync-orders'])->name('store.action');
        });

        Route::controller(\App\Http\Controllers\Admin\PlanController::class)->group(function () {
            Route::get('/plans', 'index')->middleware('admin.can:plans')->name('plans');
            Route::middleware('admin.can:plans.manage')->group(function () {
                Route::get('/plans/new', 'create')->name('plans.create');
                Route::post('/plans', 'store')->name('plans.store');
                Route::post('/plans/modules', 'matrix')->name('plans.matrix');
                Route::post('/plans/catalog', 'catalog')->name('plans.catalog');
                Route::post('/plans/pricing-page', 'pricingPage')->name('plans.pricing');
                Route::get('/plans/{plan}', 'edit')->whereNumber('plan')->name('plans.edit');
                Route::post('/plans/{plan}', 'update')->whereNumber('plan')->name('plans.update');
            });
        });

        Route::middleware('admin.can:settings')->controller(\App\Http\Controllers\Admin\SettingsController::class)->group(function () {
            Route::get('/settings', 'show')->name('settings');
            Route::post('/settings', 'update')->name('settings.update');
        });

        Route::middleware('admin.can:finance')->controller(\App\Http\Controllers\Admin\FinanceController::class)->group(function () {
            Route::get('/finance', 'show')->name('finance');
            Route::post('/finance/entries', 'store')->name('finance.store');
            Route::post('/finance/entries/{entry}', 'update')->whereNumber('entry')->name('finance.update');
            Route::post('/finance/entries/{entry}/delete', 'destroy')->whereNumber('entry')->name('finance.destroy');
            Route::post('/finance/settings', 'settings')->name('finance.settings');
            Route::post('/finance/railway', 'railway')->name('finance.railway');
            Route::post('/finance/railway/refresh', 'railwayRefresh')->name('finance.railway.refresh');
        });

        Route::middleware('admin.can:email')->controller(\App\Http\Controllers\Admin\EmailController::class)->group(function () {
            Route::get('/email', 'index')->name('email');
            Route::post('/email/settings', 'saveSettings')->name('email.settings');
            Route::get('/email/log', 'log')->name('email.log');
            Route::get('/email/providers/new', 'create')->name('email.create');
            Route::post('/email/providers', 'store')->name('email.store');
            Route::get('/email/providers/{provider}', 'edit')->whereNumber('provider')->name('email.edit');
            Route::post('/email/providers/{provider}', 'update')->whereNumber('provider')->name('email.update');
            Route::post('/email/providers/{provider}/test', 'test')->whereNumber('provider')->middleware('throttle:10,1')->name('email.test');
            Route::post('/email/providers/{provider}/{action}', 'action')->whereNumber('provider')->whereIn('action', ['resume', 'toggle', 'up', 'down', 'delete'])->name('email.action');
        });

        Route::middleware('admin.can:legal')->controller(\App\Http\Controllers\Admin\LegalController::class)->group(function () {
            Route::get('/legal', 'index')->name('legal');
            Route::post('/legal/details', 'saveDetails')->name('legal.details');
            Route::get('/legal/new', 'create')->name('legal.create');
            Route::post('/legal', 'store')->name('legal.store');
            Route::get('/legal/{page}', 'edit')->whereNumber('page')->name('legal.edit');
            Route::post('/legal/{page}', 'update')->whereNumber('page')->name('legal.update');
            Route::post('/legal/{page}/preview', 'preview')->whereNumber('page')->name('legal.preview');
            Route::get('/legal/{page}/versions/{version}', 'version')->whereNumber(['page', 'version'])->name('legal.version');
            Route::post('/legal/{page}/versions/{version}/restore', 'restore')->whereNumber(['page', 'version'])->name('legal.restore');
        });

        Route::middleware('admin.can:team')->controller(\App\Http\Controllers\Admin\TeamController::class)->group(function () {
            Route::get('/team', 'index')->name('team');
            Route::post('/team', 'invite')->name('team.invite');
            Route::post('/team/{user}', 'update')->whereNumber('user')->name('team.update');
        });

        Route::get('/account', [\App\Http\Controllers\Admin\AuthController::class, 'account'])->name('account');
        Route::post('/account/password', [\App\Http\Controllers\Admin\AuthController::class, 'updatePassword'])->middleware('throttle:5,1')->name('account.password');
    });
});
