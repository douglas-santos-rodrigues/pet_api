<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('prontuario_registros', function (Blueprint $table): void {
            $table->timestampTz('push_agendamento_atualizado_em', 6)->nullable();
        });
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tutor_id')->constrained('tutores', 'id_tutor')->cascadeOnDelete();
            $table->unsignedBigInteger('session_token_id')->nullable();
            $table->string('platform', 7);
            $table->text('token')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->char('revoke_hash', 64);
            $table->boolean('revoked')->default(false);
            $table->timestampTz('active_since', 6);
            $table->index(['tutor_id', 'session_token_id']);
        });
        Schema::create('push_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_id')->constrained('prontuario_registros', 'id_registro')->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained('push_devices')->cascadeOnDelete();
            $table->foreignUuid('tutor_id')->constrained('tutores', 'id_tutor')->cascadeOnDelete();
            $table->char('binding_hash', 64);
            $table->char('token_hash', 64)->nullable();
            $table->unsignedSmallInteger('lead_hours');
            $table->timestampTz('scheduled_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('next_attempt_at', 6);
            $table->string('state', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('ticket')->nullable();
            $table->timestampTz('accepted_at', 6)->nullable();
            $table->unique(['record_id', 'device_id', 'tutor_id', 'scheduled_at', 'lead_hours'], 'push_delivery_once');
            $table->index(['state', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
        Schema::dropIfExists('push_devices');
        Schema::table('prontuario_registros', fn (Blueprint $table) => $table->dropColumn('push_agendamento_atualizado_em'));
    }
};
