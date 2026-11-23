<?php

namespace App\Domain;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Encryption\DecryptException;

final class PushReminders
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/';

    public function run(): void
    {
        if (! config('pet.push_enabled')) {
            return;
        }

        $now = now('UTC')->toImmutable();
        $this->collectReceipts($now->toIso8601String(), $now->subMinutes(15)->toIso8601String());
        DB::table('push_deliveries')->where('expires_at', '<=', $now->toIso8601String())
            ->whereIn('state', ['pending', 'sending'])->update(['state' => 'failed']);
        DB::table('push_deliveries')->where('accepted_at', '<=', $now->subDay()->toIso8601String())
            ->where('state', 'accepted')->update(['state' => 'failed']);
        DB::table('push_deliveries')->where('expires_at', '<', $now->subDays(30)->toIso8601String())->delete();

        foreach ([24, 1] as $hours) {
            $this->enqueue($hours, $now->addHours($hours)->subMinutes(5)->toIso8601String(), $now->addHours($hours)->toIso8601String());
        }
        for ($batch = 0; $batch < 5; $batch++) {
            if (! $this->sendBatch()) break;
        }
    }

    private function enqueue(int $hours, string $after, string $through): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO push_deliveries
                (id, record_id, device_id, tutor_id, binding_hash, lead_hours, scheduled_at, expires_at, next_attempt_at)
            SELECT gen_random_uuid(), r.id_registro, d.id, p.id_tutor, d.revoke_hash, lead.hours, r.dt_agendamento,
                due.at + interval '5 minutes', due.at
            FROM prontuario_registros r
            CROSS JOIN (SELECT CAST(? AS integer) AS hours) lead
            CROSS JOIN LATERAL (SELECT r.dt_agendamento - lead.hours * interval '1 hour' AS at) due
            JOIN pets p ON p.id_pet = r.id_pet AND p.deleted_at IS NULL
            JOIN tutores t ON t.id_tutor = p.id_tutor AND t.ativo AND t.email_verificado_em IS NOT NULL AND t.deleted_at IS NULL
            JOIN push_devices d ON d.tutor_id = t.id_tutor AND d.token IS NOT NULL AND d.active_since <= due.at
            WHERE r.status = 'AGENDADO' AND r.deleted_at IS NULL AND r.dt_agendamento > ? AND r.dt_agendamento <= ?
                AND COALESCE(r.push_agendamento_atualizado_em, r.created_at) <= due.at
            ON CONFLICT (record_id, device_id, tutor_id, scheduled_at, lead_hours) DO NOTHING
            SQL, [$hours, $after, $through]);
    }

    private function sendBatch(): bool
    {
        $at = now('UTC')->toIso8601String();
        $ids = DB::transaction(function () use ($at) {
            $ids = DB::table('push_deliveries')->where('state', 'pending')->where('next_attempt_at', '<=', $at)
                ->where('expires_at', '>', $at)->orderBy('next_attempt_at')->orderBy('id')->limit(100)
                ->lock('FOR UPDATE SKIP LOCKED')->pluck('id');
            DB::table('push_deliveries')->whereIn('id', $ids)->update(['state' => 'sending', 'attempts' => DB::raw('attempts + 1')]);
            return $ids;
        });
        if ($ids->isEmpty()) return false;

        $valid = DB::table('push_deliveries as delivery')->whereIn('delivery.id', $ids)
            ->join('prontuario_registros as r', 'r.id_registro', '=', 'delivery.record_id')
            ->join('pets as p', 'p.id_pet', '=', 'r.id_pet')
            ->join('tutores as t', 't.id_tutor', '=', 'p.id_tutor')
            ->join('push_devices as d', 'd.id', '=', 'delivery.device_id')
            ->whereColumn('d.tutor_id', 'p.id_tutor')->whereColumn('delivery.tutor_id', 'p.id_tutor')
            ->whereColumn('delivery.binding_hash', 'd.revoke_hash')->whereColumn('delivery.scheduled_at', 'r.dt_agendamento')
            ->where('r.status', 'AGENDADO')->where('r.dt_agendamento', '>', $at)
            ->whereNull('r.deleted_at')->whereNull('p.deleted_at')->whereNull('t.deleted_at')
            ->where('t.ativo', true)->whereNotNull('t.email_verificado_em')->whereNotNull('d.token')
            ->whereRaw("COALESCE(r.push_agendamento_atualizado_em, r.created_at) <= delivery.scheduled_at - delivery.lead_hours * interval '1 hour'")
            ->get(['delivery.*', 'd.token as device_token', 'd.token_hash as current_token_hash']);

        DB::table('push_deliveries')->whereIn('id', $ids->diff($valid->pluck('id')))->update(['state' => 'failed']);
        $deliveries = [];
        $messages = [];
        foreach ($valid as $delivery) {
            try {
                $token = Crypt::decryptString($delivery->device_token);
            } catch (DecryptException) {
                $this->update($delivery, ['state' => 'failed']);
                continue;
            }
            $delivery->token_hash = $delivery->current_token_hash;
            $this->update($delivery, ['token_hash' => $delivery->token_hash]);
            $deliveries[] = $delivery;
            $messages[] = [
                'to' => $token, 'title' => 'Lembrete do Prontuário Pet', 'body' => 'Você tem um compromisso próximo.',
                'channelId' => 'procedimentos', 'sound' => 'default',
                'expiration' => Carbon::parse($delivery->expires_at)->getTimestamp(),
                'data' => ['kind' => 'procedure-reminder', 'recordId' => $delivery->record_id, 'deliveryId' => $delivery->id],
            ];
        }
        if ($messages === []) return true;

        try {
            $response = $this->client()->post(self::ENDPOINT.'send', $messages);
        } catch (ConnectionException) {
            // An indeterminate request can already have been accepted by Expo.
            DB::table('push_deliveries')->whereIn('id', array_column($deliveries, 'id'))->update(['state' => 'failed']);
            return true;
        }

        foreach ($deliveries as $index => $delivery) {
            $ticket = $response->successful() ? $response->json("data.$index") : null;
            if (is_array($ticket) && ($ticket['status'] ?? null) === 'ok' && is_string($ticket['id'] ?? null)) {
                $this->update($delivery, ['state' => 'accepted', 'ticket' => $ticket['id'], 'accepted_at' => now('UTC')]);
            } else {
                $temporary = $response->status() === 429 || $response->serverError();
                $this->failure($delivery, $temporary ? 'MessageRateExceeded' : ($ticket['details']['error'] ?? null));
            }
        }
        return true;
    }

    private function collectReceipts(string $now, string $olderThan): void
    {
        $batch = DB::table('push_deliveries')->where('state', 'accepted')->where('accepted_at', '<=', $olderThan)
            ->where('next_attempt_at', '<=', $now)->orderBy('next_attempt_at')->limit(300)->get();
        if ($batch->isEmpty()) return;

        try {
            $response = $this->client()->post(self::ENDPOINT.'getReceipts', ['ids' => $batch->pluck('ticket')->all()]);
        } catch (ConnectionException) {
            return;
        }
        if (! $response->successful()) return;
        foreach ($batch as $delivery) {
            $receipt = $response->json('data.'.$delivery->ticket);
            if (($receipt['status'] ?? null) === 'ok') {
                $this->update($delivery, ['state' => 'sent']);
            } elseif (($receipt['status'] ?? null) === 'error') {
                $this->failure($delivery, $receipt['details']['error'] ?? null);
            } else {
                $this->update($delivery, ['next_attempt_at' => now('UTC')->addMinutes(5)]);
            }
        }
    }

    private function failure(object $delivery, mixed $error): void
    {
        if ($error === 'DeviceNotRegistered') {
            DB::table('push_devices')->where('id', $delivery->device_id)->where('token_hash', $delivery->token_hash)
                ->where('revoke_hash', $delivery->binding_hash)->update(['token' => null]);
        }
        $next = now('UTC')->addMinutes($delivery->attempts);
        if ($error === 'MessageRateExceeded' && $delivery->attempts < 3 && $next->lt(Carbon::parse($delivery->expires_at))) {
            $this->update($delivery, ['state' => 'pending', 'next_attempt_at' => $next, 'ticket' => null, 'accepted_at' => null]);
            return;
        }
        $this->update($delivery, ['state' => 'failed']);
    }

    private function update(object $delivery, array $fields): void
    {
        DB::table('push_deliveries')->where('id', $delivery->id)->where('state', $delivery->state)->update($fields);
    }

    private function client(): PendingRequest
    {
        $client = Http::acceptJson()->connectTimeout(3)->timeout(6);
        return ($secret = config('pet.push_access_token')) ? $client->withToken($secret) : $client;
    }
}
