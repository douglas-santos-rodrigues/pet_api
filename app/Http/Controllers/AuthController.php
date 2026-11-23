<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Models\Tutor;
use Google\Client;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'nome' => 'required|string|max:150',
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('tutores')],
            'senha' => ['required', 'string', 'confirmed', PasswordRule::min(10), 'max:72', self::passwordLength(...)],
        ]);
        $tutor = DB::transaction(function () use ($data, $request) {
            $tutor = Tutor::create(['nome' => $data['nome'], 'email' => $data['email'], 'senha' => Hash::make($data['senha'])]);
            Audit::write('tutores', $tutor->getKey(), 'CRIAR', $request, actor: $tutor);
            return $tutor;
        });
        $tutor->sendEmailVerificationNotification();

        return response()->json(['id_tutor' => $tutor->getKey(), 'email' => $tutor->email], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:255', 'senha' => ['required', 'string', 'max:72', self::passwordLength(...)], 'dispositivo' => 'nullable|string|max:100']);
        return DB::transaction(function () use ($request, $data) {
            $tutor = Tutor::where('email', $data['email'])->where('ativo', true)->lockForUpdate()->first();
            abort_unless($tutor && $tutor->senha && Hash::check($data['senha'], $tutor->senha), 401, 'Credenciais inválidas.');

            return $this->session($request, $tutor, $data['dispositivo'] ?? 'api');
        });
    }

    public function google(Request $request)
    {
        $data = $request->validate(['id_token' => 'required|string|max:8192', 'dispositivo' => 'nullable|string|max:100']);
        $claims = $this->googleClaims($data['id_token']);
        $email = mb_strtolower($claims['email']);
        [$tutor, $created] = DB::transaction(function () use ($claims, $email, $request): array {
            $tutor = Tutor::where('google_sub', $claims['sub'])->first();
            if ($tutor) {
                return [$tutor, false];
            }
            abort_if(Tutor::withTrashed()->where('email', $email)->exists(), 409, 'Vincule o Google pela conta existente.');
            $authoritative = str_ends_with($email, '@gmail.com') || ! empty($claims['hd']);
            $tutor = Tutor::create([
                'nome' => mb_substr($claims['name'] ?? 'Tutor', 0, 150),
                'email' => $email, 'google_sub' => $claims['sub'], 'email_verificado_em' => $authoritative ? now() : null,
            ]);
            Audit::write('tutores', $tutor->getKey(), 'CRIAR', $request, actor: $tutor);
            return [$tutor, true];
        });
        abort_unless($tutor->ativo, 401);
        if ($created && ! $tutor->hasVerifiedEmail()) {
            $tutor->sendEmailVerificationNotification();
        }

        return $this->session($request, $tutor, $data['dispositivo'] ?? 'google');
    }

    public function linkGoogle(Request $request)
    {
        $data = $request->validate(['id_token' => 'required|string|max:8192']);
        $claims = $this->googleClaims($data['id_token']);
        DB::transaction(function () use ($request, $claims): void {
            $tutor = Tutor::lockForUpdate()->findOrFail($request->user()->getKey());
            abort_if($tutor->email !== mb_strtolower($claims['email']), 422, 'Use o mesmo e-mail verificado da conta.');
            abort_if(($tutor->google_sub && $tutor->google_sub !== $claims['sub'])
                || Tutor::withTrashed()->where('google_sub', $claims['sub'])->whereKeyNot($tutor->getKey())->exists(), 409);
            $tutor->update(['google_sub' => $claims['sub']]);
            Audit::write('tutores', $tutor->getKey(), 'VINCULAR_GOOGLE', $request);
        });

        return response()->noContent();
    }

    public function verify(Request $request, string $id, string $hash)
    {
        abort_unless($request->hasValidSignature(), 403);
        DB::transaction(function () use ($request, $id, $hash): void {
            $account = Tutor::where('ativo', true)->lockForUpdate()->findOrFail($id);
            abort_unless(hash_equals(sha1($account->email), $hash), 403);
            if (! $account->hasVerifiedEmail()) {
                $account->markEmailAsVerified();
                Audit::write('tutores', $id, 'VERIFICAR_EMAIL', $request, actor: $account);
                event(new Verified($account));
            }
        });

        return response()->noContent();
    }

    public function resend(Request $request)
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->noContent();
    }

    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:255']);
        DB::transaction(function () use ($data): void {
            $tutor = Tutor::where('email', $data['email'])->where('ativo', true)->lockForUpdate()->first();
            if ($tutor?->senha) {
                Password::sendResetLink(['email' => $tutor->email, 'ativo' => true]);
            }
        });

        return response()->noContent();
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email:rfc|max:255', 'token' => 'required|string',
            'senha' => ['required', 'string', 'confirmed', PasswordRule::min(10), 'max:72', self::passwordLength(...)],
        ]);
        $status = DB::transaction(function () use ($request, $data) {
            $account = Tutor::where('email', $data['email'])->where('ativo', true)->lockForUpdate()->first();
            if (! $account?->senha) {
                return Password::INVALID_USER;
            }
            return Password::reset([
                'email' => $data['email'], 'token' => $data['token'], 'password' => $data['senha'], 'ativo' => true,
            ], function (Tutor $tutor, string $senha) use ($request): void {
                $tutor->update(['senha' => Hash::make($senha)]);
                $tutor->tokens()->delete();
                DB::table('sessions')->where('user_id', $tutor->getKey())->delete();
                Audit::write('tutores', $tutor->getKey(), 'REDEFINIR_SENHA', $request, actor: $tutor);
                event(new PasswordReset($tutor));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'Solicitação de recuperação inválida ou expirada.']);
        }
        if ($request->hasSession() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            DB::transaction(function () use ($request, $token): void {
                Tutor::lockForUpdate()->findOrFail($request->user()->getKey());
                DB::table('push_devices')->where('session_token_id', $token->getKey())->update(PushDeviceController::inactive());
                $token->delete();
            });
        } elseif ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function session(Request $request, Tutor $tutor, string $device)
    {
        $tutor->update(['ultimo_acesso_em' => now()]);
        if ($request->hasSession()) {
            Auth::guard('web')->login($tutor);
            $request->session()->regenerate();
            return response()->json(['email_verificado' => $tutor->hasVerifiedEmail()]);
        }

        $expires = now()->addDays(30);
        $token = $tutor->createToken($device, ['tutor'], $expires);

        return response()->json([
            'access_token' => $token->plainTextToken, 'token_type' => 'Bearer',
            'expires_at' => $expires->toISOString(), 'email_verificado' => $tutor->hasVerifiedEmail(),
        ])->header('Cache-Control', 'no-store');
    }

    private function googleClaims(string $idToken): array
    {
        $audiences = config('pet.google_client_ids');
        abort_if($audiences === [], 503, 'Login Google indisponível.');
        foreach ($audiences as $audience) {
            try {
                $claims = (new Client(['client_id' => $audience]))->verifyIdToken($idToken);
            } catch (\UnexpectedValueException|\InvalidArgumentException) {
                $claims = false;
            } catch (\GuzzleHttp\Exception\TransferException) {
                abort(503, 'Login Google indisponível.');
            }
            if ($claims && ! empty($claims['sub']) && ! empty($claims['email'])
                && in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
                && filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL)) {
                return $claims;
            }
        }
        abort(401, 'Identidade Google inválida.');
    }

    public static function passwordLength(string $field, mixed $value, \Closure $fail): void
    {
        if (is_string($value) && strlen($value) > 72) {
            $fail('A senha pode ter até 72 bytes.');
        }
    }
}
