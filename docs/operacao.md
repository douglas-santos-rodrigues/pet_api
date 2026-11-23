# Operação da API

## Configuração de produção

- Gere uma `APP_KEY` exclusiva e armazene os segredos fora do código. Configure HTTPS, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` e as origens web autorizadas. Aponte o servidor web para `public/` e encaminhe rotas para `public/index.php`.
- Use PostgreSQL com TLS conforme o provedor e uma identidade com o menor privilégio compatível com a operação. Use uma identidade de implantação para migrations e mantenha uma cópia dos dados antes de mudanças de esquema.
- Use provedor SMTP autenticado e jamais habilite o registro de mensagens que contenham links de senha ou verificação. Configure a URL dos clientes e audite o funcionamento do envio em homologação.
- Em produção use `PET_STORAGE_DISK=s3`, bucket bloqueado para acesso público e credencial restrita ao prefixo da aplicação. Valide upload e download no provedor escolhido. Ajuste `upload_max_filesize`, `post_max_size` e o limite do proxy para acomodar `PET_UPLOAD_MAX_KIB`. Quando houver ClamAV, configure `PET_SCANNER_BIN` com o caminho do `clamscan`. Uma falha no scanner configurado impede o upload.
- Rode `php artisan schedule:run` a cada minuto no agendador do host. O agendador limpa tokens expirados. Defina com os parceiros antes de automatizar o descarte de chaves de idempotência, que permanecem válidas enquanto armazenadas.
- Execute `php artisan migrate --force` na implantação e rode `php artisan config:cache` e `php artisan route:cache` com as variáveis do ambiente final. Fixe a versão das dependências com o `composer.lock` após a primeira instalação bem-sucedida.

## Lembretes push

- Em homologação, aplique `php artisan migrate` e confira `php artisan schedule:list`. O comando `pet:enviar-lembretes` executa a cada minuto pelo agendador. Aplique as mesmas migrações antes de publicar o backend. Habilite `PET_PUSH_ENABLED=true` após configurar as credenciais do projeto Expo. Quando a segurança reforçada do Expo estiver habilitada, configure `PET_EXPO_PUSH_ACCESS_TOKEN` apenas no servidor.
- Associe o app a um projeto EAS e configure FCM v1 para o pacote Android e APNs para o bundle iOS. Consulte a [configuração de push do Expo](https://docs.expo.dev/push-notifications/push-notifications-setup/). No ambiente de build, defina `EXPO_PUBLIC_PUSH_ENABLED=true`, `EXPO_PUBLIC_EAS_PROJECT_ID` e o endereço HTTPS da API. No Android, informe o caminho do arquivo `google-services.json` em `GOOGLE_SERVICES_JSON`.
- Instale as dependências do app e gere um build nativo novo. Use aparelhos de homologação para confirmar permissão, envio em primeiro plano, segundo plano e com o app fechado. Confira a navegação ao tocar no push e a revogação após logout sem conexão. [SDK Expo Notifications](https://docs.expo.dev/versions/v56.0.0/sdk/notifications/)
- O agendador consulta agendamentos futuros, envia 24 h e 1 h antes e tolera até cinco minutos de atraso. Uma tentativa com resultado indeterminado por falha de conexão fica sem reenvio automático. Acompanhe os totais por estado em `push_deliveries` e confira os recibos do Expo sem expor tokens ou dados do prontuário. Desligue `PET_PUSH_ENABLED` para interromper os envios.
- Para testar com o Expo simulado, execute `php tests/tools/push-reminders-smoke.php` na pasta `app`, com `APP_ENV=testing`, banco dedicado com sufixo `_test`, migrações aplicadas e catálogos populados.

## Empresas e documentos

Os comandos `pet:empresa cadastrar|ativar|permissao|suspender|rotacionar` exigem acesso ao console do servidor. Restrinja esse acesso à equipe operadora. Credenciais novas são exibidas apenas na emissão. Entregue-as por canal seguro. Um segredo nunca deve entrar no histórico de comandos, nos logs nem em chamados.

Empresas consultam pelo código exato. A resposta não inclui o perfil do tutor. Por padrão, o histórico de outra autoria omite descrição, observações, local e JSON estruturado. Arquivos de outra autoria só podem ser compartilhados se a classificação for `COMPARTILHAVEL` e `PET_PUBLIC_ATTACHMENTS` estiver habilitado após validação de privacidade. Cópias oficiais não são entregues pela API empresarial regular.

Para preparar uma cópia oficial, a equipe verifica por processo próprio a autorização vigente do responsável para aquele pet e empresa. Com o protocolo dessa autorização, use `php artisan pet:copia-oficial UUID_ANEXO UUID_EMPRESA PROTOCOLO --confirmar`. O comando registra a exportação e grava a cópia no storage local privado. A equipe entrega o arquivo pelo canal aprovado e remove a cópia de trabalho conforme a política de retenção. O comando não envia arquivos à empresa. Registre a evidência da autorização em sistema documental controlado antes da exportação.

## Homologação e resposta operacional

1. Em ambiente isolado, crie dois tutores, pets fictícios e duas empresas com diferentes permissões. Verifique a matriz de acesso, inclusive download após bloqueio e depois de transferência.
2. Faça backup do banco e dos objetos. Restaure ambos em ambiente separado e compare uma amostra de anexos pelo SHA-256 guardado no banco. Revise o procedimento com a equipe responsável.
3. Monitore respostas 401, 403, 404, 429 e 5xx, especialmente consultas por empresa e falhas de escrita ou limpeza no storage. Use o identificador de requisição para correlacionar incidentes. Defina limites no proxy e na aplicação.
4. Antes de autorizar consultas a dados reais, valide finalidade, base legal, projeções, requisitos de entrega de cópias, perfis de operadores, prazos de retenção e descarte de auditoria, arquivos e backups.
