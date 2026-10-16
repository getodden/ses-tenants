<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $owner = fn (Blueprint $table) => match ((string) config('ses-tenants.persistence.owner_key', 'int')) {
            'uuid' => $table->uuidMorphs('owner'),
            'ulid' => $table->ulidMorphs('owner'),
            default => $table->morphs('owner'),
        };

        // What an owner sent and what came of it, a day at a time.
        Schema::create('ses_usage', function (Blueprint $table) use ($owner) {
            $table->id();
            $owner($table);
            $table->date('day');
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('bounced')->default(0);
            $table->unsignedInteger('complained')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'day']);
        });

        // What each sending domain sent a day, for its warm-up ceiling.
        Schema::create('ses_domain_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sending_domain_id')->constrained('ses_sending_domains')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('sent')->default(0);
            $table->timestamps();

            $table->unique(['sending_domain_id', 'day']);
        });

        // An owner's standing: paused or not, warned or not, and the policy last set at SES.
        Schema::create('ses_owner_status', function (Blueprint $table) use ($owner) {
            $table->id();
            $owner($table);
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->string('pause_source', 20)->nullable();
            $table->string('ses_policy', 20)->nullable();
            $table->timestamp('warned_at')->nullable();
            $table->date('rates_from')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });

        // Identities that could not be removed when an owner or a domain went away, retried by a command.
        Schema::create('ses_orphaned_identities', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('tenant_name')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ses_orphaned_identities');
        Schema::dropIfExists('ses_owner_status');
        Schema::dropIfExists('ses_domain_usage');
        Schema::dropIfExists('ses_usage');
    }
};
