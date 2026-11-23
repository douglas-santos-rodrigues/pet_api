<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('integration_requests', function (Blueprint $table): void {
            $table->foreignUuid('id_empresa')->references('id_empresa')->on('empresas');
            $table->string('key', 100);
            $table->string('fingerprint', 64);
            $table->foreignUuid('id_registro')->nullable()->references('id_registro')->on('prontuario_registros');
            $table->foreignUuid('id_anexo')->nullable()->references('id_anexo')->on('anexos');
            $table->timestampTz('created_at')->useCurrent()->index();
            $table->primary(['id_empresa', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['integration_requests', 'sessions', 'cache_locks', 'cache', 'password_reset_tokens', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
