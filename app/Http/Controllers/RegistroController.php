<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\Identifiers;
use App\Http\Requests\RegistroRequest;
use App\Http\Resources\PetResource;
use App\Http\Resources\RegistroResource;
use App\Models\Empresa;
use App\Models\Pet;
use App\Models\Registro;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RegistroController
{
    public function pet(Request $request, string $pet)
    {
        $animal = $this->findPet($request, $pet);
        Audit::write('pets', $animal->getKey(), 'CONSULTAR', $request);

        return response()->json((new PetResource($animal, integration: true))->resolve($request));
    }

    public function index(Request $request, string $pet)
    {
        $animal = $this->findPet($request, $pet);
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(RegistroRequest::STATUSES)],
            'id_tipo_procedimento' => 'sometimes|integer|min:1',
            'inicio' => ['sometimes', 'required', 'date', RegistroRequest::INSTANT],
            'fim' => ['sometimes', 'required', 'date', RegistroRequest::INSTANT],
            'ordenacao' => 'sometimes|in:asc,desc',
            'por_pagina' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1',
        ]);
        if (isset($filters['inicio'], $filters['fim']) && Carbon::parse($filters['fim'])->lessThan(Carbon::parse($filters['inicio']))) {
            throw ValidationException::withMessages(['fim' => 'O fim deve ser igual ou posterior ao início.']);
        }
        $query = $animal->registros();
        foreach (['status', 'id_tipo_procedimento'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        $date = match ($filters['status'] ?? null) {
            'REALIZADO' => 'dt_realizacao',
            'AGENDADO', 'CANCELADO', 'NAO_REALIZADO' => 'dt_agendamento',
            default => 'COALESCE(dt_realizacao, dt_agendamento)',
        };
        if (isset($filters['inicio'])) {
            $query->whereRaw($date.' >= ?', [$filters['inicio']]);
        }
        if (isset($filters['fim'])) {
            $query->whereRaw($date.' <= ?', [$filters['fim']]);
        }
        $company = $this->company($request);
        $direction = $filters['ordenacao'] ?? (($filters['status'] ?? null) === 'AGENDADO' ? 'asc' : 'desc');
        $page = $query->orderByRaw($date.' '.$direction)->orderBy('id_registro')->paginate($filters['por_pagina'] ?? 25)->withQueryString();
        if ($company) {
            Audit::write('pets', $animal->getKey(), 'CONSULTAR_PRONTUARIO', $request);
        }
        $page->through(fn (Registro $registro) => (new RegistroResource($registro, $company))->resolve($request));

        return response()->json($page);
    }

    public function show(Request $request, string $registro)
    {
        $record = self::visible($request, $registro);
        $company = $this->company($request);
        Audit::write('prontuario_registros', $record->getKey(), 'CONSULTAR', $request);

        return response()->json((new RegistroResource($record, $company, withAttachments: true))->resolve($request));
    }

    public function store(RegistroRequest $request, string $pet)
    {
        $input = $request->validated();
        $actor = $this->company($request) ?? $request->user();
        $key = $actor instanceof Empresa ? self::idempotencyKey($request) : null;
        [$record, $created] = DB::transaction(function () use ($request, $pet, $input, $actor, $key): array {
            $animal = $this->findPet($request, $pet, locked: true);
            if ($key !== null) {
                $prior = self::reserve($actor, $key, ['action' => 'registro', 'pet' => $animal->getKey(), 'data' => $input]);
                if ($prior->id_registro !== null) {
                    return [Registro::findOrFail($prior->id_registro), false];
                }
            }
            $data = $this->validateState($input, $animal);
            $company = $actor instanceof Empresa;
            $record = $animal->registros()->create($data + [
                'origem' => $company ? 'EMPRESA' : 'TUTOR',
                'id_empresa_origem' => $company ? $actor->getKey() : null,
                'id_tutor_origem' => $company ? null : $actor->getKey(),
                'push_agendamento_atualizado_em' => now(),
            ]);
            if ($key !== null) {
                DB::table('integration_requests')->where('id_empresa', $actor->getKey())->where('key', $key)->update(['id_registro' => $record->getKey()]);
            }
            Audit::write('prontuario_registros', $record->getKey(), 'CRIAR', $request, after: $record->toArray());

            return [$record->refresh(), true];
        }, attempts: 3);

        return response()->json((new RegistroResource($record, $this->company($request)))->resolve($request), $created ? 201 : 200);
    }

    public function update(RegistroRequest $request, string $registro)
    {
        $actor = $this->company($request) ?? $request->user();
        $input = $request->validated();
        $record = DB::transaction(function () use ($request, $registro, $actor, $input) {
            $record = self::visible($request, $registro, locked: true);
            Gate::forUser($actor)->authorize('update', $record);
            if ($actor instanceof Empresa && empty($input['motivo_correcao'])) {
                throw ValidationException::withMessages(['motivo_correcao' => 'Informe o motivo da correção.']);
            }
            $reason = $input['motivo_correcao'] ?? null;
            unset($input['motivo_correcao']);
            $current = $record->getAttributes();
            $current['dados_adicionais'] = $record->dados_adicionais === null ? null : (array) $record->dados_adicionais;
            $data = $this->validateState($input + $current, $record->pet, $record);
            $before = $record->toArray();
            $record->fill(array_intersect_key($data, $input));
            if ($record->isDirty(['status', 'dt_agendamento'])) {
                $record->push_agendamento_atualizado_em = now();
            }
            $record->save();
            Audit::write('prontuario_registros', $record->getKey(), 'CORRIGIR', $request, $before, $record->toArray() + ['motivo_correcao' => $reason]);

            return $record->refresh();
        }, attempts: 3);

        return response()->json((new RegistroResource($record, $this->company($request)))->resolve($request));
    }

    public static function visible(Request $request, string $id, bool $locked = false): Registro
    {
        $record = Registro::findOrFail($id);
        $petQuery = Pet::query();
        if (! $request->attributes->has('empresa')) {
            $petQuery->where('id_tutor', $request->user()->getKey());
        }
        if ($locked) {
            $petQuery->lockForUpdate();
        }
        $pet = $petQuery->findOrFail($record->id_pet);
        if ($locked) {
            $record = Registro::lockForUpdate()->findOrFail($id);
        }
        $record->setRelation('pet', $pet);

        return $record;
    }

    public static function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Informe uma chave de idempotência válida.']);
        }
        return $key;
    }

    public static function reserve(Empresa $actor, string $key, array $payload): object
    {
        $sort = function (array &$value) use (&$sort): void {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($payload);
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $identity = ['id_empresa' => $actor->getKey(), 'key' => $key];
        DB::table('integration_requests')->insertOrIgnore($identity + ['fingerprint' => $fingerprint]);
        $prior = DB::table('integration_requests')->where($identity)->lockForUpdate()->first();
        abort_unless(hash_equals($prior->fingerprint, $fingerprint), 409, 'Chave de idempotência já usada com outro conteúdo.');

        return $prior;
    }

    private function findPet(Request $request, string $identity, bool $locked = false): Pet
    {
        $query = Pet::query();
        if ($this->company($request)) {
            $code = Identifiers::normalizePet($identity);
            abort_unless($code !== null, 404);
            $query->where('cpf_pet', $code);
        } else {
            $query->whereKey($identity)->where('id_tutor', $request->user()->getKey());
        }
        return $query->when($locked, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    private function company(Request $request): ?Empresa
    {
        return $request->attributes->get('empresa');
    }

    private function validateState(array $data, Pet $pet, ?Registro $current = null): array
    {
        $status = $data['status'];
        $start = isset($data['dt_agendamento']) ? Carbon::parse($data['dt_agendamento']) : null;
        $end = isset($data['dt_fim_agendamento']) ? Carbon::parse($data['dt_fim_agendamento']) : null;
        $done = isset($data['dt_realizacao']) ? Carbon::parse($data['dt_realizacao']) : null;
        $type = DB::table('tipos_procedimento')->where('id_tipo_procedimento', $data['id_tipo_procedimento'])->first();
        if (($status === 'REALIZADO') !== ($done !== null) || ($done && $done->isFuture())) {
            throw ValidationException::withMessages(['dt_realizacao' => 'Informe uma data de realização não futura somente para registros realizados.']);
        }
        if ($status !== 'REALIZADO' && ! $start) {
            throw ValidationException::withMessages(['dt_agendamento' => 'Informe a data do compromisso.']);
        }
        if ($end && (! $start || $end->lessThanOrEqualTo($start))) {
            throw ValidationException::withMessages(['dt_fim_agendamento' => 'O fim deve ser posterior ao início.']);
        }
        if ($start && ! $type->permite_agendamento) {
            throw ValidationException::withMessages(['dt_agendamento' => 'O tipo informado não permite agendamento.']);
        }
        if ($current && $current->status !== $status && $current->status !== 'AGENDADO') {
            throw ValidationException::withMessages(['status' => 'Somente um compromisso agendado pode mudar de situação.']);
        }
        if (! $current && ! in_array($status, ['AGENDADO', 'REALIZADO'], true)) {
            throw ValidationException::withMessages(['status' => 'Crie o registro como agendado ou realizado.']);
        }
        $parent = $data['id_registro_origem'] ?? null;
        $visited = $current ? [$current->getKey() => true] : [];
        while ($parent) {
            $source = $pet->registros()->find($parent);
            if (! $source || isset($visited[$source->getKey()])) {
                throw ValidationException::withMessages(['id_registro_origem' => 'A origem deve pertencer ao pet e não pode formar um ciclo.']);
            }
            $visited[$source->getKey()] = true;
            $parent = $source->id_registro_origem;
        }
        if (isset($data['dados_adicionais'])) {
            $rules = match ($type->nome) {
                'Vacina', 'Reforço' => ['nome_vacina' => 'string|max:150', 'fabricante' => 'string|max:150', 'lote' => 'string|max:80', 'dose' => 'string|max:80'],
                'Exame' => ['nome_exame' => 'string|max:150', 'resultado_resumido' => 'string|max:2000'],
                'Pesagem' => ['peso_kg' => 'numeric|min:0|max:9999.999|decimal:0,3'],
                default => [],
            };
            $fields = $data['dados_adicionais'];
            if (array_diff_key($fields, $rules)) {
                throw ValidationException::withMessages(['dados_adicionais' => 'O objeto contém campos não previstos para o procedimento.']);
            }
            validator(['dados_adicionais' => $fields], collect($rules)->mapWithKeys(fn ($rule, $key) => ['dados_adicionais.'.$key => 'sometimes|required|'.$rule])->all())->validate();
            $data['dados_adicionais'] = (object) $fields;
        }
        foreach (['dt_agendamento' => $start, 'dt_fim_agendamento' => $end, 'dt_realizacao' => $done] as $field => $instant) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $instant?->utc();
            }
        }
        return $data;
    }
}
