<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

final class Tutor extends User implements MustVerifyEmailContract
{
    use HasApiTokens, HasUuids, MustVerifyEmail, Notifiable, SoftDeletes;

    protected $table = 'tutores';
    protected $primaryKey = 'id_tutor';
    protected $guarded = [];
    protected $hidden = ['senha', 'google_sub', 'deleted_at'];
    protected $rememberTokenName = null;

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'email_verificado_em' => 'datetime', 'ultimo_acesso_em' => 'datetime'];
    }

    public function getAuthPasswordName(): string
    {
        return 'senha';
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verificado_em !== null;
    }

    public function markEmailAsVerified()
    {
        return $this->forceFill(['email_verificado_em' => $this->freshTimestamp()])->save();
    }

    public function pets()
    {
        return $this->hasMany(Pet::class, 'id_tutor');
    }
}
