<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\PushReminders;
use App\Models\Pet;
use App\Models\Tutor;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function check(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function api(string $method, string $path, int $expected, array $data = [], ?string $token = null): array
{
    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token) $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    auth()->forgetGuards();
    $request = Request::create('/api/v1'.$path, $method, [], [], [], $headers, json_encode($data, JSON_THROW_ON_ERROR));
    $response = app(Kernel::class)->handle($request);
    check($response->getStatusCode() === $expected, "$method $path: status {$response->getStatusCode()} esperado $expected");
    return json_decode($response->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
}

function tutor(): Tutor
{
    return Tutor::create([
        'nome' => 'Tutor fictício', 'email' => Str::uuid().'@example.test',
        'email_verificado_em' => now(), 'senha' => Hash::make('senha-de-teste'),
    ]);
}

check(app()->environment('testing') && str_ends_with(DB::selectOne('select current_database() as name')->name, '_test'),
    'Use o PostgreSQL isolado de testes.');
config(['pet.push_enabled' => true, 'pet.push_access_token' => null, 'cache.default' => 'array']);
Carbon::setTestNow('2030-01-01T10:00:00Z');
DB::beginTransaction();
try {
    $owner = tutor();
    $other = tutor();
    $token = $owner->createToken('smoke', ['tutor'], now()->addMinutes(90))->plainTextToken;
    $otherToken = $other->createToken('smoke', ['tutor'], now()->addDays(3))->plainTextToken;
    $installation = Str::uuid()->toString();
    $devicePath = '/tutor/notificacoes/dispositivos/'.$installation;
    $revocationPath = '/notificacoes/dispositivos/'.$installation.'/revogar';
    $device = ['platform' => 'android', 'expo_token' => 'ExpoPushToken[fixtureToken123]', 'revoke_secret' => str_repeat('a', 64)];
    api('PUT', $devicePath, 401, $device);
    api('PUT', $devicePath, 204, $device, $token);
    api('PUT', $devicePath, 204, $device, $token);
    check(DB::table('push_devices')->where('id', $installation)->value('token') !== $device['expo_token'], 'Token sem criptografia');

    $pet = api('POST', '/tutor/pets', 201, ['nome' => 'Pet fictício', 'id_especie' => DB::table('especies')->value('id_especie')], $token);
    $petPath = '/tutor/pets/'.$pet['id_pet'].'/prontuario';
    $payload = ['titulo' => 'Consulta fictícia', 'id_tipo_procedimento' => DB::table('tipos_procedimento')->where('nome', 'Consulta')->value('id_tipo_procedimento'), 'status' => 'AGENDADO'];
    $normal = api('POST', $petPath, 201, $payload + ['dt_agendamento' => '2030-01-02T12:00:00Z'], $token)['id_registro'];
    $near = api('POST', $petPath, 201, $payload + ['dt_agendamento' => '2030-01-01T12:30:00Z'], $token)['id_registro'];
    api('POST', $petPath, 201, $payload + ['dt_agendamento' => '2030-01-01T10:30:00Z'], $token);
    $cancelled = api('POST', $petPath, 201, $payload + ['dt_agendamento' => '2030-01-02T12:00:00Z'], $token)['id_registro'];
    api('PATCH', '/tutor/prontuario/'.$cancelled, 200, ['status' => 'CANCELADO'], $token);
    $completed = api('POST', $petPath, 201, $payload + ['dt_agendamento' => '2030-01-02T12:00:00Z'], $token)['id_registro'];
    api('PATCH', '/tutor/prontuario/'.$completed, 200, ['status' => 'REALIZADO', 'dt_realizacao' => now()->toIso8601String()], $token);
    $moved = api('POST', $petPath, 201, $payload + ['dt_agendamento' => '2030-01-02T14:00:00Z'], $token)['id_registro'];
    $transferRecord = api('POST', $petPath, 201, $payload + ['dt_agendamento' => '2030-01-02T12:30:00Z'], $token)['id_registro'];
    Carbon::setTestNow('2030-01-01T12:01:00Z');
    $renewed = $owner->createToken('smoke', ['tutor'], now()->addMinutes(5))->plainTextToken;
    api('PATCH', '/tutor/prontuario/'.$moved, 200, ['dt_agendamento' => '2030-01-02T12:00:00Z'], $renewed);

    $sent = [];
    $receiptError = null;
    $insideSend = false;
    $service = app(PushReminders::class);
    Http::preventStrayRequests();
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => function ($request) use (&$sent, &$insideSend, $service) {
            if (! $insideSend) {
                $insideSend = true;
                $service->run();
                $insideSend = false;
            }
            return Http::response(['data' => array_map(function (array $item) use (&$sent) {
                $sent[] = $item;
                return ['status' => 'ok', 'id' => 'ticket-'.count($sent)];
            }, $request->data())]);
        },
        'https://exp.host/--/api/v2/push/getReceipts' => function ($request) use (&$receiptError) {
            $receipt = $receiptError ? ['status' => 'error', 'details' => ['error' => $receiptError]] : ['status' => 'ok'];
            return Http::response(['data' => array_fill_keys($request->data()['ids'], $receipt)]);
        },
    ]);
    Carbon::setTestNow('2030-01-01T11:30:00Z');
    $service->run();
    check(count($sent) === 1 && $sent[0]['data']['recordId'] === $near, 'Compromisso próximo ou deduplicação');
    Carbon::setTestNow('2030-01-01T12:02:00Z');
    $service->run();
    $service->run();
    check(count($sent) === 2 && $sent[1]['data']['recordId'] === $normal, 'Lembrete de 24 horas ou envio retroativo');
    check($sent[1]['body'] === 'Você tem um compromisso próximo.' && array_keys($sent[1]['data']) === ['kind', 'recordId', 'deliveryId'], 'Dados expostos no push');
    Carbon::setTestNow('2030-01-02T11:00:00Z');
    $service->run();
    check(count(array_filter($sent, fn (array $item) => $item['data']['recordId'] === $normal)) === 2, 'Lembrete de 1 hora após expirar a sessão');
    $receiptError = 'DeviceNotRegistered';
    Carbon::setTestNow('2030-01-02T11:16:00Z');
    $service->run();
    check(DB::table('push_devices')->where('id', $installation)->value('token') === null, 'Token inválido continuou ativo');

    $newBinding = array_replace($device, ['expo_token' => 'ExpoPushToken[newFixtureToken]', 'revoke_secret' => str_repeat('b', 64)]);
    api('PUT', $devicePath, 204, $newBinding, $otherToken);
    api('POST', $revocationPath, 204, ['revoke_secret' => $device['revoke_secret']]);
    check(DB::table('push_devices')->where('id', $installation)->value('tutor_id') === $other->getKey()
        && DB::table('push_devices')->where('id', $installation)->value('token') !== null, 'Revogação antiga removeu a nova conta');
    Pet::findOrFail($pet['id_pet'])->update(['id_tutor' => $other->getKey()]);
    Carbon::setTestNow('2030-01-02T11:20:00Z');
    Carbon::setTestNow('2030-01-02T11:30:00Z');
    $service->run();
    check(collect($sent)->contains(fn (array $item) => $item['data']['recordId'] === $transferRecord && $item['to'] === $newBinding['expo_token']), 'Destinatário após transferência');
    api('POST', '/tutor/auth/logout', 204, [], $otherToken);
    check(DB::table('push_devices')->where('id', $installation)->value('token') === null, 'Logout não revogou o push');
    echo "Push: horários, deduplicação, sessão expirada, cancelamento, reagendamento, transferência, revogação e recibos passaram.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    $failed = true;
} finally {
    DB::rollBack();
    Carbon::setTestNow();
}
exit(isset($failed) ? 1 : 0);
