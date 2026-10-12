<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final MVP F7 (Shopify sync engine), F3 (cost rules) and F15 (costs): a tenant-scoped copy of the
 * catalog and orders, versioned unit costs, and a record of every sync run.
 * Amounts are in the shop's currency. No customer names, emails or addresses are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_id');
            $table->string('title');
            $table->string('handle')->nullable();
            $table->string('status', 20)->nullable(); // ACTIVE | DRAFT | ARCHIVED
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->text('image_url')->nullable();
            $table->boolean('is_gift_card')->default(false);
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable(); // deleted in Shopify
            $table->timestamps();

            $table->unique(['store_id', 'shopify_id']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_id');
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->string('title')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->decimal('compare_at_price', 14, 2)->nullable();
            // Cost from Shopify ("Cost per item") and the merchant's own cost in the app, which wins.
            $table->decimal('shopify_cost', 14, 4)->nullable();
            $table->decimal('app_cost', 14, 4)->nullable();
            $table->integer('inventory_quantity')->nullable();
            $table->boolean('inventory_tracked')->default(true);
            $table->boolean('requires_shipping')->default(true);
            $table->unsignedInteger('position')->default(1);
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'shopify_id']);
            $table->index(['store_id', 'sku']);
            $table->index(['store_id', 'inventory_item_id']);
        });

        // Unit cost history, so a cost change never rewrites past profit (F12 acceptance).
        Schema::create('cost_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('cost', 14, 4)->nullable();
            $table->string('source', 20); // shopify | app | import
            $table->timestamp('effective_from');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['variant_id', 'effective_from']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_id');
            $table->string('name', 40)->nullable(); // #1001
            $table->timestamp('processed_at'); // when the order was placed
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('test')->default(false);
            $table->string('currency', 3)->nullable(); // shop currency
            $table->string('presentment_currency', 3)->nullable();
            $table->string('financial_status', 30)->nullable();
            $table->string('fulfillment_status', 30)->nullable();
            $table->string('source_name', 60)->nullable(); // sales channel
            $table->text('landing_site')->nullable();
            $table->text('referring_site')->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 190)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable(); // Shopify customer ID only
            $table->decimal('gross_sales', 14, 2)->default(0); // line price × quantity
            $table->decimal('total_discounts', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0); // after discounts, before shipping and tax
            $table->decimal('total_shipping', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->decimal('total_refunded', 14, 2)->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->string('payment_gateway', 60)->nullable();
            $table->string('shipping_country', 2)->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'shopify_id']);
            $table->index(['store_id', 'processed_at']);
            $table->index(['store_id', 'customer_id']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_id');
            $table->unsignedBigInteger('product_shopify_id')->nullable();
            $table->unsignedBigInteger('variant_shopify_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->string('variant_title')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('refunded_quantity')->default(0);
            $table->decimal('price', 14, 2)->default(0); // unit price before discounts
            $table->decimal('discount', 14, 2)->default(0); // total discount allocated to the line
            $table->decimal('unit_cost', 14, 4)->nullable(); // cost at order time (cost snapshot)
            $table->boolean('is_gift_card')->default(false);
            $table->timestamps();

            $table->unique(['order_id', 'shopify_id']);
            $table->index(['store_id', 'variant_shopify_id']);
            $table->index(['store_id', 'product_shopify_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_id');
            $table->timestamp('processed_at');
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'shopify_id']);
            $table->index(['store_id', 'processed_at']);
        });

        // F3 cost rules: payment fees, shipping, handling and fixed monthly costs.
        Schema::create('cost_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // payment_fee | shipping | handling | fixed_cost | ad_spend
            $table->string('name', 120);
            $table->json('config'); // e.g. {percent, fixed, gateway} | {basis, amount, country} | {amount, starts_on, ends_on}
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'type']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // products | orders
            $table->string('trigger', 20)->default('initial'); // initial | scheduled | manual | admin
            $table->string('status', 20)->default('queued'); // queued | running | done | failed
            $table->text('cursor')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('records')->default(0);
            $table->timestamp('since')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'kind', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        foreach (['sync_runs', 'cost_rules', 'refunds', 'order_lines', 'orders', 'cost_snapshots', 'variants', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
