<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCryptoTables extends Migration
{
    public function up()
    {
        // Paiements cryptocurrencies
        Schema::create('crypto_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('merchant_id')->nullable()->index();
            
            $table->string('currency', 10)->index();
            $table->decimal('amount_crypto', 18, 8);
            $table->decimal('amount_fiat', 14, 2);
            $table->string('fiat_currency', 3)->default('USD');
            
            $table->string('wallet_address')->index();
            $table->string('transaction_hash')->nullable()->unique()->index();
            
            $table->enum('status', [
                'pending', 'confirming', 'confirmed', 'completed', 
                'expired', 'failed', 'refunded'
            ])->default('pending')->index();
            
            $table->unsignedInteger('confirmations')->default(0);
            $table->unsignedInteger('required_confirmations');
            
            $table->decimal('exchange_rate', 18, 8);
            $table->decimal('network_fee', 18, 8)->nullable();
            $table->decimal('platform_fee', 14, 2)->default(0);
            $table->decimal('fee_percentage', 5, 2)->default(0);
            
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('webhook_sent_at')->nullable();
            
            $table->string('reference_code', 50)->unique()->index();
            $table->string('payment_method', 50)->default('native');
            $table->text('description')->nullable();
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['currency', 'status']);
            $table->index(['created_at', 'status']);
            $table->index(['merchant_id', 'created_at']);
        });
        
        // Adresses blockchain
        Schema::create('crypto_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable()->index();
            
            $table->string('address', 255)->unique()->index();
            $table->string('currency', 10)->index();
            $table->string('label')->nullable();
            
            $table->string('derivation_path')->nullable();
            $table->text('public_key')->nullable();
            $table->string('script_type')->nullable();
            
            $table->decimal('balance', 18, 8)->default(0);
            $table->timestamp('balance_updated_at')->nullable();
            
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['address', 'currency']);
            $table->index(['currency', 'is_active']);
        });
        
        // Transactions blockchain
        Schema::create('blockchain_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->index();
            
            $table->string('tx_hash', 255)->unique()->index();
            $table->string('block_hash', 255)->nullable();
            $table->unsignedBigInteger('block_number')->nullable()->index();
            
            $table->string('from_address', 255);
            $table->string('to_address', 255);
            
            $table->decimal('amount', 18, 8);
            $table->unsignedBigInteger('gas_used')->nullable();
            $table->decimal('gas_price', 18, 8)->nullable();
            $table->unsignedInteger('nonce')->nullable();
            
            $table->text('input_data')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'failed'])->default('pending')->index();
            
            $table->unsignedInteger('confirmations')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            
            $table->json('raw_data')->nullable();
            
            $table->timestamps();
            
            $table->foreign('payment_id')->references('id')->on('crypto_payments')
                  ->onDelete('cascade');
            $table->index(['payment_id', 'status']);
        });
        
        // Taux de change
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            
            $table->string('crypto_currency', 10)->index();
            $table->string('fiat_currency', 3)->index();
            
            $table->decimal('rate', 18, 8);
            $table->string('source')->default('coingecko');
            
            $table->decimal('volume_24h', 20, 2)->nullable();
            $table->decimal('market_cap', 20, 2)->nullable();
            $table->decimal('price_change_24h', 8, 2)->nullable();
            
            $table->timestamps();
            
            $table->unique(['crypto_currency', 'fiat_currency']);
            $table->index(['updated_at']);
        });
        
        // Webhooks
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->index();
            
            $table->string('event_type')->index();
            $table->text('webhook_url');
            $table->json('payload');
            
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('next_retry_at')->nullable()->index();
            
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            
            $table->timestamps();
            
            $table->foreign('payment_id')->references('id')->on('crypto_payments')
                  ->onDelete('cascade');
            $table->index(['event_type', 'completed_at']);
        });
        
        // Endpoints webhook
        Schema::create('payment_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            
            $table->text('url');
            $table->json('events');
            $table->string('secret', 255);
            
            $table->boolean('is_active')->default(true)->index();
            $table->text('description')->nullable();
            
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['merchant_id', 'is_active']);
        });
        
        // Logs d'audit
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->index();
            
            $table->string('action');
            $table->json('changes')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            
            $table->timestamp('created_at');
            
            $table->foreign('payment_id')->references('id')->on('crypto_payments')
                  ->onDelete('cascade');
            $table->index(['created_at', 'action']);
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('payment_webhook_endpoints');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('blockchain_transactions');
        Schema::dropIfExists('crypto_addresses');
        Schema::dropIfExists('crypto_payments');
    }
}

