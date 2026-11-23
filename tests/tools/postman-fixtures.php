<?php

declare(strict_types=1);

use App\Domain\Identifiers;
use App\Models\Empresa;
use App\Models\Tutor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
    fwrite(STDERR, "Use APP_ENV=testing e um banco PostgreSQL separado com sufixo _test.\n");
    exit(1);
}

$configuredDatabase = DB::connection()->getDatabaseName();
if (DB::selectOne('SELECT current_database() AS database')->database !== $configuredDatabase) {
    fwrite(STDERR, "O PostgreSQL selecionou um banco diferente do esperado.\n");
    exit(1);
}

$cnpj = null;
$prefix = '9999'.strtoupper(bin2hex(random_bytes(4)));
for ($digits = 0; $digits < 100 && $cnpj === null; $digits++) {
    $cnpj = Identifiers::cnpj($prefix.sprintf('%02d', $digits));
}
if ($cnpj === null) {
    throw new RuntimeException('Não foi possível gerar um CNPJ fictício.');
}

$password = bin2hex(random_bytes(16));
$secret = bin2hex(random_bytes(32));
[$tutor, $company] = DB::transaction(function () use ($cnpj, $password, $secret): array {
    $tutor = Tutor::create([
        'nome' => 'Tutor fictício do Postman',
        'email' => 'postman-'.Str::lower(Str::random(16)).'@example.test',
        'senha' => Hash::make($password),
        'email_verificado_em' => now(),
    ]);
    $company = Empresa::create([
        'razao_social' => 'Empresa fictícia do Postman',
        'cnpj' => $cnpj,
        'email' => $tutor->email,
        'api_client_id' => (string) Str::uuid(),
        'api_client_secret_hash' => Hash::make($secret),
        'api_ativo' => true,
        'api_permissao' => 'LEITURA_ESCRITA',
    ]);

    return [$tutor, $company];
});

$variables = [
    'base_url' => rtrim(config('app.url'), '/'),
    'tutor_email' => $tutor->email,
    'tutor_senha' => $password,
    'empresa_client_id' => $company->api_client_id,
    'empresa_client_secret' => $secret,
];

echo json_encode([
    'id' => (string) Str::uuid(),
    'name' => 'Prontuário Pet — PostgreSQL de testes',
    'values' => array_map(fn ($key, $value) => [
        'key' => $key,
        'value' => $value,
        'type' => in_array($key, ['tutor_senha', 'empresa_client_secret'], true) ? 'secret' : 'default',
        'enabled' => true,
    ], array_keys($variables), array_values($variables)),
    '_postman_variable_scope' => 'environment',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
