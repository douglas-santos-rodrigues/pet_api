<?php

declare(strict_types=1);

require __DIR__.'/../app/Domain/Identifiers.php';

use App\Domain\Identifiers;

$dsn = getenv('PET_SCHEMA_DSN');
if (! $dsn || ! str_starts_with($dsn, 'pgsql:')) {
    throw new RuntimeException('Defina PET_SCHEMA_DSN com uma conexão PostgreSQL de teste.');
}
$db = new PDO($dsn, getenv('PET_SCHEMA_USER') ?: null, getenv('PET_SCHEMA_PASSWORD') ?: null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if (! str_ends_with($db->query('SELECT current_database()')->fetchColumn(), '_test')) {
    throw new RuntimeException('Use um banco dedicado com sufixo _test.');
}

function expectError(PDO $db, string $sql, array $bindings, string $code): void
{
    $db->exec('SAVEPOINT expect_error');
    try {
        $db->prepare($sql)->execute($bindings);
    } catch (PDOException $error) {
        $db->exec('ROLLBACK TO SAVEPOINT expect_error');
        $db->exec('RELEASE SAVEPOINT expect_error');
        if ($error->getCode() === $code) {
            return;
        }
        throw $error;
    }
    $db->exec('ROLLBACK TO SAVEPOINT expect_error');
    $db->exec('RELEASE SAVEPOINT expect_error');
    throw new RuntimeException('Esperava erro PostgreSQL '.$code);
}

$schema = 'pet_schema_'.bin2hex(random_bytes(6));
$db->beginTransaction();
try {
    $db->exec('CREATE SCHEMA '.$schema);
    $db->exec('SET LOCAL search_path TO '.$schema);
    $db->exec(file_get_contents(__DIR__.'/../database/schema/pet-domain.sql'));
    $actual = $db->query('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename')->fetchAll(PDO::FETCH_COLUMN);
    $expected = ['anexos', 'auditoria', 'empresas', 'especies', 'pets', 'prontuario_registros', 'racas', 'tipos_procedimento', 'tutores'];
    if ($actual !== $expected) {
        throw new RuntimeException('Conjunto de tabelas de domínio diferente do esperado.');
    }

    $owner = '00000000-0000-4000-8000-000000000001';
    $next = '00000000-0000-4000-8000-000000000002';
    $pet = '00000000-0000-4000-8000-000000000003';
    $otherPet = '00000000-0000-4000-8000-000000000004';
    $record = '00000000-0000-4000-8000-000000000005';
    $otherRecord = '00000000-0000-4000-8000-000000000006';
    $code = Identifiers::pet();
    $otherCode = Identifiers::pet();
    $tutorSql = 'INSERT INTO tutores (id_tutor, nome, email, senha) VALUES (?, ?, ?, ?)';
    $db->prepare($tutorSql)->execute([$owner, 'Tutor fictício', 'tutor@example.test', 'hash-fixture']);
    expectError($db, $tutorSql, [$next, 'Outro tutor', 'Tutor@example.test', 'hash-fixture'], '23505');
    $db->prepare($tutorSql)->execute([$next, 'Outro tutor', 'outro@example.test', 'hash-fixture']);
    $db->exec("INSERT INTO especies (nome) VALUES ('Cão'), ('Gato')");
    $db->exec("INSERT INTO racas (id_especie, nome) VALUES (2, 'Raça felina')");
    $db->exec("INSERT INTO tipos_procedimento (nome, categoria) VALUES ('Consulta', 'CLINICO')");
    $petSql = 'INSERT INTO pets (id_pet, cpf_pet, id_tutor, id_especie, id_raca, nome) VALUES (?, ?, ?, ?, ?, ?)';
    expectError($db, $petSql, [$pet, $code, $owner, 1, 1, 'Pet de teste'], '23503');
    expectError($db, $petSql, [$pet, $code, null, 1, null, 'Pet de teste'], '23502');
    $db->prepare($petSql)->execute([$pet, $code, $owner, 1, null, 'Pet de teste']);
    expectError($db, $petSql, [$otherPet, $code, $owner, 2, 1, 'Outro pet'], '23505');
    $db->prepare($petSql)->execute([$otherPet, $otherCode, $owner, 2, 1, 'Outro pet']);
    expectError($db, 'UPDATE pets SET cpf_pet = ? WHERE id_pet = ?', [Identifiers::pet(), $pet], '23514');

    $recordSql = "INSERT INTO prontuario_registros (id_registro, id_pet, id_tipo_procedimento, id_tutor_origem, titulo, origem, status, dt_agendamento) VALUES (?, ?, 1, ?, 'Consulta', 'TUTOR', 'AGENDADO', ?)";
    expectError($db, $recordSql, [$record, $pet, null, '2030-01-02T12:00:00-03:00'], '23514');
    expectError($db, $recordSql, [$record, $pet, $owner, null], '23514');
    $db->prepare($recordSql)->execute([$record, $pet, $owner, '2030-01-02T12:00:00-03:00']);
    $db->prepare($recordSql)->execute([$otherRecord, $otherPet, $owner, '2030-01-02T12:00:00-03:00']);
    expectError($db, 'UPDATE prontuario_registros SET id_registro_origem = ? WHERE id_registro = ?', [$otherRecord, $record], '23503');
    expectError($db, 'UPDATE prontuario_registros SET id_registro_origem = ? WHERE id_registro = ?', [$record, $record], '23514');
    expectError($db, "UPDATE prontuario_registros SET dados_adicionais = '[]'::jsonb WHERE id_registro = ?", [$record], '23514');
    expectError($db, 'UPDATE prontuario_registros SET dt_fim_agendamento = ? WHERE id_registro = ?', ['2030-01-02T11:00:00-03:00', $record], '23514');
    $db->prepare("UPDATE prontuario_registros SET status = 'REALIZADO', dt_realizacao = ? WHERE id_registro = ?")->execute(['2030-01-02T13:00:00-03:00', $record]);
    $db->prepare('UPDATE pets SET id_tutor = ?, versao_tutoria = 1 WHERE id_pet = ?')->execute([$next, $pet]);
    $statement = $db->prepare('SELECT cpf_pet, id_tutor, id_tutor_origem, prontuario_registros.status FROM pets JOIN prontuario_registros USING (id_pet) WHERE id_registro = ?');
    $statement->execute([$record]);
    if ($statement->fetch(PDO::FETCH_ASSOC) !== ['cpf_pet' => $code, 'id_tutor' => $next, 'id_tutor_origem' => $owner, 'status' => 'REALIZADO']) {
        throw new RuntimeException('A transferência não preservou o prontuário e sua autoria.');
    }

    $db->exec(file_get_contents(__DIR__.'/../database/schema/pet-domain-down.sql'));
    $remaining = $db->query('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()')->fetchAll(PDO::FETCH_COLUMN);
    if ($remaining !== []) {
        throw new RuntimeException('O rollback deixou tabelas no esquema de teste.');
    }
    fwrite(STDOUT, "PostgreSQL: migrations e restrições das nove tabelas validadas.\n");
} finally {
    $db->rollBack();
}
