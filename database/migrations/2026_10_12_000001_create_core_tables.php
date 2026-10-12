<?php

use App\Support\LegalDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform core shared with Growvia: stores and their staff, Shopify billing, plans and
 * per-store access, usage, audit, webhooks, the internal admin team, support, email providers,
 * legal pages, finance and platform settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->string('admin_role', 20)->default('super_admin');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain')->unique();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('timezone')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('shopify_plan')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('capabilities_checked_at')->nullable();
            $table->string('plan')->nullable();
            $table->timestamp('plan_expires_at')->nullable();
            // { plan, plan_until, modules_on[], modules_off[], limits{meter: n|null}, note }
            $table->json('entitlements')->nullable();
            $table->string('entitlements_hash')->nullable();
            // Reporting preferences, alert thresholds, notification preferences (Settings).
            $table->json('settings')->nullable();
            $table->json('privacy')->nullable();
            $table->json('legal_acks')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('store_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_user_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('account_owner')->default(false);
            $table->string('role')->default('analyst');
            $table->timestamp('invited_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('store_users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'shopify_user_id']);
            $table->index(['store_id', 'email']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('plan');
            $table->string('shopify_subscription_id')->unique();
            $table->string('status');
            $table->decimal('price', 8, 2);
            $table->boolean('test')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('meter');
            $table->date('period_start');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'meter', 'period_start']);
        });

        Schema::create('upgrade_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 60);
            $table->string('from_plan', 40)->nullable();
            $table->string('to_plan', 40)->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'converted_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->json('context')->nullable();
            $table->string('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'created_at']);
            $table->index('action');
        });

        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id')->unique();
            $table->string('shop_domain');
            $table->string('topic');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['shop_domain', 'created_at']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('shopify_name', 120);
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('trial_days')->default(0);
            $table->json('modules');
            $table->json('limits');
            $table->json('features');
            $table->string('description', 300)->nullable();
            $table->string('badge', 40)->nullable();
            $table->string('support_label', 80)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $position = 0;
        foreach ((array) config('shopify.billing.plans', []) as $key => $plan) {
            DB::table('plans')->insert([
                'key' => $key, 'name' => $plan['name'], 'shopify_name' => $plan['shopify_name'] ?? $plan['name'],
                'price' => $plan['price'] ?? 0, 'modules' => json_encode(array_values($plan['includes'] ?? [])),
                'limits' => json_encode($plan['limits'] ?? []), 'features' => json_encode($plan['features'] ?? []),
                'description' => $plan['description'] ?? null, 'badge' => $plan['badge'] ?? null, 'support_label' => $plan['support'] ?? null,
                'position' => $position++, 'is_public' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('description', 300)->nullable();
            $table->boolean('enabled')->default(false); // for every store
            $table->json('store_ids')->nullable(); // or only these stores
            $table->timestamps();
        });

        // Support: merchants open tickets in the app; the team answers in the Internal Admin.
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 160);
            $table->string('category', 20);
            $table->string('priority', 10)->default('normal');
            $table->string('status', 10)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('diagnostics')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->string('last_reply_by', 10)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['status', 'last_reply_at']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author', 10);
            $table->string('author_name', 120)->nullable();
            $table->foreignId('store_user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamps();
        });

        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->string('filename', 160);
            $table->string('mime', 80);
            $table->unsignedInteger('size');
            $table->binary('content');
            $table->timestamps();
        });

        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('company', 160)->nullable();
            $table->string('store_url', 190)->nullable();
            $table->string('topic', 40);
            $table->text('message');
            $table->string('ip', 45)->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamps();
        });

        Schema::create('email_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('driver', 30);
            $table->text('credentials');
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->unsignedInteger('priority')->default(10);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('monthly_limit')->nullable();
            $table->unsignedInteger('sent_today')->default(0);
            $table->unsignedInteger('sent_month')->default(0);
            $table->date('usage_day')->nullable();
            $table->string('usage_month', 7)->nullable();
            $table->string('status', 20)->default('ok');
            $table->timestamp('paused_until')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_name')->nullable();
            $table->string('category', 40);
            $table->string('to_email');
            $table->string('subject');
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->string('message_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });

        Schema::create('finance_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->date('date');
            $table->string('description', 200);
            $table->string('category', 60)->nullable();
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Legal & policy pages, edited in the Internal Admin. `body` is what's live; `draft_*` is work in progress.
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('title', 160);
            $table->string('summary', 300)->nullable();
            $table->longText('body');
            $table->string('draft_title', 160)->nullable();
            $table->longText('draft_body')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->date('effective_at')->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('show_in_footer')->default(true);
            $table->boolean('requires_review')->default(false);
            $table->unsignedInteger('review_version')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 160);
            $table->longText('body');
            $table->string('change_summary', 300)->nullable();
            $table->date('effective_at')->nullable();
            $table->string('published_by')->nullable();
            $table->timestamp('published_at');
        });

        $now = now();
        $position = 0;
        foreach (LegalDefaults::pages() as $slug => [$title, $summary, $review, $footer, $body]) {
            $id = DB::table('legal_pages')->insertGetId([
                'slug' => $slug, 'title' => $title, 'summary' => $summary, 'body' => $body, 'version' => 1, 'effective_at' => $now->toDateString(),
                'is_published' => true, 'show_in_footer' => $footer, 'requires_review' => $review, 'position' => $position++,
                'updated_by' => 'system', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('legal_page_versions')->insert([
                'legal_page_id' => $id, 'version' => 1, 'title' => $title, 'body' => $body, 'change_summary' => 'First version.',
                'effective_at' => $now->toDateString(), 'published_by' => 'system', 'published_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['legal_page_versions', 'legal_pages', 'finance_entries', 'email_deliveries', 'email_providers', 'contact_submissions',
            'support_attachments', 'support_messages', 'support_tickets', 'feature_flags', 'platform_settings', 'plans', 'webhook_receipts',
            'audit_logs', 'upgrade_events', 'usage_records', 'subscriptions', 'store_users', 'stores'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_admin', 'admin_role', 'last_login_at', 'disabled_at']));
    }
};
