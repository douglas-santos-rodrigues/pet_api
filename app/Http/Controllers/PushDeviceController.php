<?php

namespace App\Http\Controllers;

use App\Models\Tutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class PushDeviceController
{
    public function register(Request $request, string $installation)
    {
        $installation = strtolower($installation);
        abort_unless(config('pet.push_enabled'), 503, 'Lembretes indisponíveis neste ambiente.');
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403);
        $data = $request->validate([
            'platform' => 'required|in:android,ios',
            'expo_token' => ['required', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_-]+\]$/D'],
            'revoke_secret' => 'required|string|regex:/^[a-f0-9]{64}$/D',
        ]);
        $hash = hash('sha256', $data['expo_token']);
        $revokeHash = hash('sha256', $data['revoke_secret']);

        DB::transaction(function () use ($request, $installation, $token, $data, $hash, $revokeHash): void {
            $tutor = Tutor::lockForUpdate()->findOrFail($request->user()->getKey());
            abort_unless($tutor->ativo && $tutor->hasVerifiedEmail() && $tutor->tokens()->whereKey($token->getKey())->exists(), 401);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$installation]);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$hash]);
            $device = DB::table('push_devices')->where('id', $installation)->lockForUpdate()->first();
            $sameBinding = $device && hash_equals($device->revoke_hash, $revokeHash);
            abort_if($sameBinding && ($device->revoked || $device->tutor_id !== $tutor->getKey()), 409, 'Ative os lembretes novamente.');
            abort_if($sameBinding && $device->token === null && $device->token_hash === $hash, 409, 'O token de notificações ainda está indisponível.');
            DB::table('push_devices')->where('token_hash', $hash)->where('id', '<>', $installation)->update(self::inactive());
            DB::table('push_devices')->updateOrInsert(['id' => $installation], [
                'tutor_id' => $tutor->getKey(), 'session_token_id' => $token->getKey(), 'platform' => $data['platform'],
                'token' => Crypt::encryptString($data['expo_token']), 'token_hash' => $hash, 'revoke_hash' => $revokeHash,
                'active_since' => $sameBinding ? $device->active_since : now('UTC'), 'revoked' => false,
            ]);
        }, attempts: 3);

        return response()->noContent();
    }

    public function revoke(Request $request, string $installation)
    {
        $data = $request->validate(['revoke_secret' => 'required|string|regex:/^[a-f0-9]{64}$/D']);
        DB::table('push_devices')->where('id', $installation)
            ->where('revoke_hash', hash('sha256', $data['revoke_secret']))->update(self::inactive());

        return response()->noContent();
    }

    public static function inactive(): array
    {
        return ['token' => null, 'token_hash' => null, 'session_token_id' => null, 'revoked' => true];
    }
}
