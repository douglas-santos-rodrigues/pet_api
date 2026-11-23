<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\Identifiers;
use App\Http\Requests\PetRequest;
use App\Http\Resources\PetResource;
use App\Models\Pet;
use App\Models\Tutor;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PetController
{
    public function profile(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        if (is_string($request->input('cpf'))) {
            $cpf = Identifiers::cpf($request->input('cpf'));
            if ($cpf === null) {
                throw ValidationException::withMessages(['cpf' => 'CPF inválido.']);
            }
            $request->merge(['cpf' => $cpf]);
        }
        if (is_string($request->input('cep'))) {
            $request->merge(['cep' => str_replace('-', '', $request->input('cep'))]);
        }
        $tutor = $request->user();
        $data = $request->validate([
            'nome' => 'sometimes|required|string|max:150',
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('tutores')->ignore($tutor->getKey(), 'id_tutor')],
            'cpf' => ['sometimes', 'nullable', 'string', Rule::unique('tutores')->ignore($tutor->getKey(), 'id_tutor')],
            'telefone' => 'nullable|string|max:20', 'celular' => 'nullable|string|max:20',
            'dt_nascimento' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'cep' => 'nullable|string|regex:/^[0-9]{8}$/', 'logradouro' => 'nullable|string|max:200',
            'numero' => 'nullable|string|max:20', 'complemento' => 'nullable|string|max:100',
            'bairro' => 'nullable|string|max:100', 'cidade' => 'nullable|string|max:100',
            'uf' => 'nullable|string|regex:/^[A-Z]{2}$/', 'codigo_ibge' => 'nullable|string|regex:/^[0-9]{7}$/',
            'pais' => 'nullable|string|regex:/^[A-Z]{2}$/', 'foto_url' => 'nullable|url:https|max:2048',
            'senha_atual' => ['required_with:email', 'string', 'max:72', AuthController::passwordLength(...)],
        ]);
        $changedEmail = DB::transaction(function () use ($request, $data, $tutor): bool {
            $account = Tutor::lockForUpdate()->findOrFail($tutor->getKey());
            $changedEmail = isset($data['email']) && $data['email'] !== $account->email;
            $fields = data_get($data, 'senha_atual') === null ? $data : collect($data)->except('senha_atual')->all();
            if ($changedEmail) {
                abort_unless($account->senha && \Illuminate\Support\Facades\Hash::check($data['senha_atual'], $account->senha), 403, 'Confirme a senha atual.');
                $fields['email_verificado_em'] = null;
                DB::table('password_reset_tokens')->where('email', $account->email)->delete();
                $account->tokens()->delete();
                DB::table('sessions')->where('user_id', $account->getKey())->delete();
            }
            $account->update($fields);
            Audit::write('tutores', $account->getKey(), 'ALTERAR', $request, after: ['campos' => array_keys($fields)]);
            return $changedEmail;
        });
        $tutor->refresh();
        if ($changedEmail) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            $tutor->sendEmailVerificationNotification();
        }

        return response()->json($tutor);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['por_pagina' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $pets = $request->user()->pets()->orderBy('nome')->orderBy('id_pet')->paginate($data['por_pagina'] ?? 25)->withQueryString();
        $pets->through(fn (Pet $pet) => (new PetResource($pet))->resolve($request));

        return response()->json($pets);
    }

    public function show(Request $request, string $pet)
    {
        $owned = $request->user()->pets()->findOrFail($pet);
        Gate::authorize('view', $owned);

        return response()->json((new PetResource($owned))->resolve($request));
    }

    public function store(PetRequest $request)
    {
        $data = $request->validated();
        $this->checkPet($data);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $pet = DB::transaction(function () use ($request, $data) {
                    $pet = $request->user()->pets()->create($data + ['cpf_pet' => Identifiers::pet()]);
                    Audit::write('pets', $pet->getKey(), 'CRIAR', $request);
                    return $pet->refresh();
                });
                return response()->json((new PetResource($pet))->resolve($request), 201);
            } catch (QueryException $error) {
                if ($error->getCode() !== '23505' || ! str_contains($error->errorInfo[2] ?? '', 'pets_cpf_pet_key')) {
                    throw $error;
                }
            }
        }
        abort(503, 'Não foi possível gerar o código do pet. Tente novamente.');
    }

    public function update(PetRequest $request, string $pet)
    {
        $data = $request->validated();
        $pet = DB::transaction(function () use ($request, $pet, $data) {
            $owned = $request->user()->pets()->lockForUpdate()->findOrFail($pet);
            Gate::authorize('view', $owned);
            $this->checkPet($data + $owned->getAttributes());
            $before = collect($owned->getAttributes())->only(array_keys($data))->all();
            $owned->update($data);
            Audit::write('pets', $owned->getKey(), 'ALTERAR', $request, $before, $data);
            return $owned->refresh();
        });

        return response()->json((new PetResource($pet))->resolve($request));
    }

    public function transferInvite(Request $request, string $pet)
    {
        $data = $request->validate(['email_destino' => 'required|email:rfc|max:255']);
        $owned = $request->user()->pets()->findOrFail($pet);
        $destination = Tutor::where('email', mb_strtolower($data['email_destino']))->where('ativo', true)
            ->whereNotNull('email_verificado_em')->whereKeyNot($request->user()->getKey())->first();
        if ($destination) {
            $url = URL::temporarySignedRoute('pets.transferir', now()->addHours(24), [
                'pet' => $owned->getKey(), 'destino' => $destination->getKey(), 'versao' => $owned->versao_tutoria,
            ]);
            $link = rtrim(config('pet.client_url'), '/').'/transferencia?'.http_build_query(['confirmacao' => $url]);
            \Illuminate\Support\Facades\Mail::raw("Convite para assumir a tutoria de um pet. Abra o link e entre em sua conta para confirmar:\n".$link, function ($message) use ($destination): void {
                $message->to($destination->email)->subject('Convite de transferência de tutoria');
            });
            Audit::write('pets', $owned->getKey(), 'CONVIDAR_TRANSFERENCIA', $request);
        }

        return response()->json(['message' => 'Se a conta de destino estiver apta, ela receberá o convite.'], 202);
    }

    public function transferConfirm(Request $request, string $pet)
    {
        abort_unless($request->hasValidSignature() && $request->query('destino') === $request->user()->getKey(), 403);
        DB::transaction(function () use ($request, $pet): void {
            $animal = Pet::lockForUpdate()->findOrFail($pet);
            $new = $request->user()->getKey();
            abort_if((string) $animal->versao_tutoria !== $request->query('versao') || $animal->id_tutor === $new, 409, 'Convite já utilizado ou substituído.');
            $old = $animal->id_tutor;
            $animal->update(['id_tutor' => $new, 'versao_tutoria' => $animal->versao_tutoria + 1]);
            Audit::write('pets', $pet, 'TRANSFERIR_TUTORIA', $request, ['id_tutor' => $old], ['id_tutor' => $new]);
        });

        return response()->noContent();
    }

    private function checkPet(array $data): void
    {
        $birth = $data['dt_nascimento'] ?? null;
        $death = $data['dt_obito'] ?? null;
        $castration = $data['dt_castracao'] ?? null;
        if (! empty($data['id_raca']) && ! DB::table('racas')->where('id_especie', $data['id_especie'])->where('id_raca', $data['id_raca'])->exists()) {
            throw ValidationException::withMessages(['id_raca' => 'A raça deve pertencer à espécie selecionada.']);
        }
        if (! empty($data['nascimento_estimado']) && ! $birth) {
            throw ValidationException::withMessages(['dt_nascimento' => 'Informe a data de nascimento estimada.']);
        }
        if ($death && (($data['status'] ?? null) !== 'FALECIDO' || ($birth && $death < $birth))) {
            throw ValidationException::withMessages(['dt_obito' => 'A data de óbito é incompatível com o estado ou o nascimento.']);
        }
        if ($castration && (empty($data['castrado']) || ($birth && $castration < $birth) || ($death && $castration > $death))) {
            throw ValidationException::withMessages(['dt_castracao' => 'A data de castração é incompatível com o cadastro.']);
        }
    }
}
