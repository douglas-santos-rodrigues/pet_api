<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Tutor;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class PetApiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_tutor_registers_verifies_and_logs_out(): void
    {
        Notification::fake();
        $payload = ['nome' => 'Tutor de teste', 'email' => 'Tutor@Example.test', 'senha' => 'uma-senha-ficticia', 'senha_confirmation' => 'uma-senha-ficticia'];
        $this->postJson('/api/v1/tutor/auth/register', $payload)->assertCreated()->assertJsonPath('email', 'tutor@example.test');
        $tutor = Tutor::firstOrFail();
        Notification::assertSentTo($tutor, VerifyEmail::class);
        $token = $this->postJson('/api/v1/tutor/auth/login', ['email' => $payload['email'], 'senha' => $payload['senha']])
            ->assertOk()->json('access_token');
        $this->getJson('/api/v1/tutor/pets', $this->auth($token))->assertForbidden();
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), ['id' => $tutor->getKey(), 'hash' => sha1($tutor->email)]);
        $this->getJson($link)->assertNoContent();
        $this->refreshAuth();
        $this->getJson('/api/v1/tutor/pets', $this->auth($token))->assertOk();
        $this->postJson('/api/v1/tutor/auth/logout', [], $this->auth($token))->assertNoContent();
        $this->refreshAuth();
        $this->getJson('/api/v1/tutor/pets', $this->auth($token))->assertUnauthorized();
    }

    public function test_tutor_agenda_ownership_private_attachment_and_transfer(): void
    {
        Storage::fake('local');
        $owner = $this->tutor('owner');
        $destination = $this->tutor('destination');
        $ownerToken = $this->token($owner);
        $destinationToken = $this->token($destination);
        $pet = $this->pet($ownerToken);
        $base = "/api/v1/tutor/pets/{$pet['id_pet']}";
        $this->refreshAuth();
        $this->getJson($base, $this->auth($destinationToken))->assertNotFound();
        $this->refreshAuth();
        $agenda = $this->postJson($base.'/prontuario', $this->record() + [
            'status' => 'AGENDADO', 'dt_agendamento' => now()->addDay()->toIso8601String(),
        ], $this->auth($ownerToken))->assertCreated()->json();
        $reg = '/api/v1/tutor/prontuario/'.$agenda['id_registro'];
        $this->getJson($base.'/prontuario?status=AGENDADO', $this->auth($ownerToken))->assertJsonCount(1, 'data');
        $this->patchJson($reg, ['status' => 'REALIZADO', 'dt_realizacao' => now()->subMinute()->toIso8601String()], $this->auth($ownerToken))
            ->assertOk()->assertJsonPath('status', 'REALIZADO');
        $this->getJson($base.'/prontuario?status=REALIZADO', $this->auth($ownerToken))->assertJsonCount(1, 'data');
        $first = $this->post($reg.'/anexos', ['arquivo' => $this->pdf('a.pdf')], $this->auth($ownerToken))->assertCreated()->json();
        $this->post($reg.'/anexos', ['arquivo' => $this->pdf('b.pdf')], $this->auth($ownerToken))->assertCreated();
        $this->getJson($reg, $this->auth($ownerToken))
            ->assertJsonCount(2, 'anexos')->assertJsonMissingPath('anexos.0.caminho_arquivo')
            ->assertJsonPath('pode_anexar', true)
            ->assertJsonPath('limite_anexo_bytes', config('pet.upload_max_kib') * 1024);
        $download = '/api/v1/tutor/anexos/'.$first['id_anexo'].'/download';
        $this->get($download, $this->auth($ownerToken))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->refreshAuth();
        $this->get($first['download_url'], $this->auth($destinationToken))->assertNotFound();
        $link = URL::temporarySignedRoute('pets.transferir', now()->addHour(), ['pet' => $pet['id_pet'], 'destino' => $destination->getKey(), 'versao' => 0]);
        $this->postJson($link, [], $this->auth($destinationToken))->assertNoContent();
        $this->postJson($link, [], $this->auth($destinationToken))->assertConflict();
        $this->getJson($base, $this->auth($destinationToken))->assertJsonPath('cpf_pet', $pet['cpf_pet']);
        $this->getJson($reg, $this->auth($destinationToken))->assertJsonPath('pode_anexar', false);
        $this->post($reg.'/anexos', ['arquivo' => $this->pdf('c.pdf')], $this->auth($destinationToken))->assertForbidden();
        $this->get($download, $this->auth($destinationToken))->assertOk();
        $this->get($first['download_url'], $this->auth($destinationToken))->assertOk();
        $this->refreshAuth();
        $this->get($first['download_url'], $this->auth($ownerToken))->assertNotFound();
        $this->assertDatabaseHas('auditoria', ['acao' => 'TRANSFERIR_TUTORIA', 'id_registro' => $pet['id_pet']]);
    }

    public function test_tutor_can_manage_a_private_pet_photo(): void
    {
        $disk = Storage::fake(config('pet.storage_disk'));
        $ownerToken = $this->token($this->tutor('photo-owner'));
        $otherToken = $this->token($this->tutor('other-photo-owner'));
        $pet = $this->pet($ownerToken);
        $base = "/api/v1/tutor/pets/{$pet['id_pet']}";
        $photo = $base.'/foto';
        $legacy = 'https://example.test/foto.jpg';
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/'
            .'2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/'
            .'8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAABQb/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCZAKIZ/9k='
        );
        $file = fn () => UploadedFile::fake()->createWithContent('pet.jpg', $jpeg);

        $this->patchJson($base, ['foto_url' => $legacy], $this->auth($ownerToken))->assertOk()->assertJsonPath('foto_enviada', false);
        $this->post($photo, ['foto' => $file()], $this->auth($ownerToken))->assertNoContent();
        $this->getJson($base, $this->auth($ownerToken))->assertOk()
            ->assertJsonPath('foto_enviada', true)->assertJsonPath('foto_url', $legacy)
            ->assertJsonPath('cpf_pet', $pet['cpf_pet'])->assertJsonMissingPath('caminho_foto');
        $response = $this->get($photo, $this->auth($ownerToken))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($jpeg, $response->streamedContent());
        $firstPath = $disk->allFiles()[0];

        $this->refreshAuth();
        $this->get($photo, $this->auth($otherToken))->assertNotFound();
        $this->post($photo, ['foto' => $file()], $this->auth($otherToken))->assertNotFound();
        $this->deleteJson($photo, [], $this->auth($otherToken))->assertNotFound();
        $this->refreshAuth();
        $this->post($photo, ['foto' => UploadedFile::fake()->createWithContent('invalida.jpg', 'texto')], $this->auth($ownerToken))
            ->assertUnprocessable()->assertJsonValidationErrors('foto');
        $disk->assertExists($firstPath);
        $this->post($photo, ['foto' => $file()], $this->auth($ownerToken))->assertNoContent();
        $disk->assertMissing($firstPath);
        $this->assertCount(1, $disk->allFiles());
        $this->deleteJson($photo, [], $this->auth($ownerToken))->assertNoContent();
        $this->deleteJson($photo, [], $this->auth($ownerToken))->assertNoContent();
        $this->get($photo, $this->auth($ownerToken))->assertNotFound();
        $this->getJson($base, $this->auth($ownerToken))->assertOk()
            ->assertJsonPath('foto_enviada', false)->assertJsonPath('foto_url', null);
        $this->assertSame([], $disk->allFiles());
    }

    public function test_external_idempotency_author_permissions_and_revocation(): void
    {
        $owner = $this->tutor('owner');
        $ownerToken = $this->token($owner);
        $pet = $this->pet($ownerToken);
        $secret = str_repeat('a', 64);
        $company = Empresa::create([
            'razao_social' => 'Integração fictícia', 'cnpj' => '12345678000195', 'email' => 'company@example.test',
            'api_client_id' => 'teste-credencial', 'api_client_secret_hash' => Hash::make($secret),
            'api_ativo' => true, 'api_permissao' => 'LEITURA',
        ]);
        $token = $this->postJson('/api/v1/integracoes/auth/token', ['client_id' => $company->api_client_id, 'client_secret' => $secret])
            ->assertOk()->json('access_token');
        $headers = $this->auth($token) + ['Idempotency-Key' => 'evento-1'];
        $petUrl = '/api/v1/integracoes/pets/'.$pet['cpf_pet'];
        $this->getJson($petUrl, $headers)->assertOk()->assertJsonMissingPath('id_tutor')->assertJsonMissingPath('email');
        $this->getJson($petUrl.'/prontuario', $headers)->assertOk();
        $payload = $this->record() + ['status' => 'REALIZADO', 'dt_realizacao' => now()->subMinute()->toIso8601String()];
        $this->postJson($petUrl.'/prontuario', $payload, $headers)->assertForbidden();
        $company->update(['api_permissao' => 'LEITURA_ESCRITA']);
        $record = $this->postJson($petUrl.'/prontuario', $payload, $headers)->assertCreated()->json();
        $this->postJson($petUrl.'/prontuario', $payload, $headers)->assertOk()->assertJsonPath('id_registro', $record['id_registro']);
        $this->postJson($petUrl.'/prontuario', array_replace($payload, ['titulo' => 'Outro']), $headers)->assertConflict();
        $this->assertDatabaseCount('prontuario_registros', 1);
        $ownUrl = '/api/v1/tutor/prontuario/'.$record['id_registro'];
        $this->refreshAuth();
        $this->patchJson($ownUrl, ['titulo' => 'Sem autoria'], $this->auth($ownerToken))->assertForbidden();
        $this->patchJson('/api/v1/integracoes/prontuario/'.$record['id_registro'], ['titulo' => 'Correção', 'motivo_correcao' => 'Ajuste da descrição'], $headers)->assertOk();
        $company->update(['api_ativo' => false]);
        $this->getJson($petUrl, $headers)->assertForbidden();
        $this->assertDatabaseHas('auditoria', ['acao' => 'CORRIGIR', 'id_empresa' => $company->getKey()]);
        self::assertStringNotContainsString($secret, DB::table('auditoria')->get()->toJson());
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function tutor(string $name): Tutor
    {
        return Tutor::create(['nome' => $name, 'email' => $name.'@example.test', 'senha' => Hash::make('senha-ficticia'), 'email_verificado_em' => now()]);
    }

    private function token(Tutor $tutor): string
    {
        return $tutor->createToken('test', ['tutor'], now()->addHour())->plainTextToken;
    }

    private function pet(string $token): array
    {
        return $this->postJson('/api/v1/tutor/pets', ['nome' => 'Pet fictício', 'id_especie' => DB::table('especies')->value('id_especie')], $this->auth($token))
            ->assertCreated()->json();
    }

    private function record(): array
    {
        return ['id_tipo_procedimento' => DB::table('tipos_procedimento')->where('nome', 'Consulta')->value('id_tipo_procedimento'), 'titulo' => 'Consulta de teste'];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }

    private function refreshAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
