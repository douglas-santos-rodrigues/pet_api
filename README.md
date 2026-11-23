# Prontuário Pet — API v1

API Laravel 12 com PostgreSQL 16+, dividida em duas superfícies. O tutor gerencia os próprios pets, a agenda, o histórico e os anexos. A empresa aprovada consulta por código exato e, se tiver permissão, publica registros identificados como empresariais. O plano original está na pasta acima.

- [Contrato OpenAPI 3.1](docs/openapi.json)
- [DER, dicionário e regras](docs/dados.md)
- [Operação e liberação](docs/operacao.md)

## Desenvolvimento local

Requisitos: PHP 8.2+, Composer 2, PostgreSQL 16+, extensões PHP `pdo_pgsql`, `mbstring`, `fileinfo`, `openssl`, `curl`, `dom` e `xml`. Use um coletor SMTP local para testar links de verificação, recuperação e transferência. Configure um banco e um usuário próprios antes de executar as migrations.

Execute na pasta `app`. Copie `.env.example` para `.env` e ajuste os valores de banco, URL e e-mail.

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan route:list --path=api/v1
php artisan serve
```

Confira `GET /up`. As rotas da API aceitam `Accept: application/json`, retornam `X-Request-Id` e usam tokens Bearer para clientes externos. O navegador pode usar uma sessão Sanctum. Nesse caso, configure `SANCTUM_STATEFUL_DOMAINS` e `PET_WEB_ORIGINS`, obtenha o cookie em `GET /sanctum/csrf-cookie` e envie credenciais e `X-XSRF-TOKEN` nas mutações.

Configure `GOOGLE_CLIENT_IDS` com os IDs de cliente permitidos, separados por vírgula. O servidor verifica o token do Google e associa a conta pelo `sub`. Para e-mails de terceiros em que o Google não confirma a titularidade atual, o tutor verifica o e-mail pela plataforma. Vincular uma conta existente requer sessão autenticada, e-mail já verificado e o mesmo endereço nas duas contas.

Os links de senha e transferência apontam para `PET_CLIENT_URL`. Enquanto a interface não existe, use o coletor de e-mails para extrair o token de redefinição ou a URL assinada e envie a requisição indicada no OpenAPI. Na transferência, envie `POST` para a URL assinada completa, com o token do tutor destinatário.

## Testes

Crie um banco separado com sufixo `_test`. O exemplo usa `prontuario_pet_test`. Se a conexão de teste for diferente da configuração local, defina `DB_HOST`, `DB_PORT`, `DB_USERNAME` e `DB_PASSWORD` antes de iniciar o comando. A suíte usa migrations no banco de teste. O sufixo é obrigatório e também se aplica a conexões definidas por `DB_URL`.

```powershell
$env:DB_DATABASE = 'prontuario_pet_test'
composer test
```

Para verificar o esquema de domínio sem dependências Composer, execute `php tests/postgres_schema.php` com `PET_SCHEMA_DSN`, `PET_SCHEMA_USER` e, se necessário, `PET_SCHEMA_PASSWORD`. Use um banco descartável com sufixo `_test`.

### Postman

Importe a [coleção do Postman](docs/prontuario-pet.postman_collection.json). Para testar o fluxo completo, use um PostgreSQL de testes separado. Crie primeiro o banco `prontuario_pet_test` com o mesmo acesso configurado no `.env`. Na pasta `app`, execute no PowerShell:

```powershell
$env:APP_ENV = 'testing'
$env:DB_DATABASE = 'prontuario_pet_test'
$env:APP_URL = 'http://127.0.0.1:8010'
php artisan migrate --seed
php tests/tools/postman-fixtures.php | Set-Content -Encoding utf8 "$env:TEMP/prontuario-pet.postman_environment.json"
php artisan serve --host=127.0.0.1 --port=8010
```

Importe também o ambiente gerado em `%TEMP%\prontuario-pet.postman_environment.json`, selecione-o no Postman e execute apenas a pasta **Fluxo automatizado** com **Run folder**. Ela cria um pet e procedimentos fictícios e captura os tokens e identificadores necessários. O arquivo de ambiente contém apenas credenciais geradas para esse banco de teste. A pasta **Operações manuais** oferece anexos, e-mail, Google e transferência. Verificação e recuperação por e-mail exigem um SMTP de testes.

## Integração rápida

Cadastre e aprove uma empresa pelo console. O CNPJ abaixo é apenas dado de teste.

```powershell
php artisan pet:empresa cadastrar 12345678000195 --razao="Empresa de teste" --email=empresa@example.test
php artisan pet:empresa ativar 12345678000195 --permissao=LEITURA_ESCRITA
```

1. Envie `client_id` e `client_secret` a `POST /api/v1/integracoes/auth/token`.
2. Use o token retornado no cabeçalho `Authorization: Bearer …`. Consulte `GET /api/v1/integracoes/pets/{cpf_pet}` e `GET /api/v1/integracoes/pets/{cpf_pet}/prontuario`.
3. Envie um `Idempotency-Key` distinto para cada procedimento ou anexo publicado. Preserve a chave nos reenvios da mesma operação.

Para a agenda, filtre `status=AGENDADO`. Para o histórico realizado, use `status=REALIZADO`. Um compromisso pode ser concluído com `PATCH` no registro original e a data efetiva em `dt_realizacao`. Envie um arquivo por chamada de upload. Um registro aceita vários arquivos.
