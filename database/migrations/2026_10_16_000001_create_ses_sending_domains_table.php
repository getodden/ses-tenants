<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ses_sending_domains', function (Blueprint $table) {
            $table->id();

            match ((string) config('ses-tenants.persistence.owner_key', 'int')) {
                'uuid' => $table->uuidMorphs('owner'),
                'ulid' => $table->ulidMorphs('owner'),
                default => $table->morphs('owner'),
            };

            $table->string('domain')->unique();
            $table->string('status')->default('pending');
            $table->json('records')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error')->nullable();
            $table->date('first_sent_on')->nullable();
            $table->unsignedSmallInteger('warmup_start_step')->default(0);
            $table->timestamp('warmup_skipped_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ses_sending_domains');
    }
};
