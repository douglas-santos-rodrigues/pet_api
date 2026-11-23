CREATE TABLE tutores (
    id_tutor uuid PRIMARY KEY,
    nome varchar(150) NOT NULL,
    cpf varchar(11) CHECK (cpf ~ '^[0-9]{11}$'),
    email varchar(255) NOT NULL,
    email_verificado_em timestamptz,
    senha varchar(255),
    google_sub varchar(255) UNIQUE,
    telefone varchar(20), celular varchar(20), dt_nascimento date,
    cep varchar(8) CHECK (cep ~ '^[0-9]{8}$'),
    logradouro varchar(200), numero varchar(20), complemento varchar(100),
    bairro varchar(100), cidade varchar(100), uf char(2), codigo_ibge varchar(7),
    pais char(2), foto_url text,
    ativo boolean NOT NULL DEFAULT true,
    ultimo_acesso_em timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    CHECK (senha IS NOT NULL OR google_sub IS NOT NULL)
);
CREATE UNIQUE INDEX tutores_email_unico ON tutores (lower(email));
CREATE UNIQUE INDEX tutores_cpf_unico ON tutores (cpf) WHERE cpf IS NOT NULL;

CREATE TABLE especies (
    id_especie bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome varchar(100) NOT NULL UNIQUE,
    nome_cientifico varchar(150),
    ativo boolean NOT NULL DEFAULT true
);
CREATE TABLE racas (
    id_raca bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_especie bigint NOT NULL REFERENCES especies,
    nome varchar(120) NOT NULL,
    ativo boolean NOT NULL DEFAULT true,
    UNIQUE (id_especie, nome),
    UNIQUE (id_especie, id_raca)
);
CREATE TABLE tipos_procedimento (
    id_tipo_procedimento bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome varchar(120) NOT NULL UNIQUE,
    descricao text,
    categoria varchar(40) NOT NULL,
    permite_agendamento boolean NOT NULL DEFAULT true,
    ativo boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE empresas (
    id_empresa uuid PRIMARY KEY,
    razao_social varchar(200) NOT NULL,
    nome_fantasia varchar(200),
    cnpj varchar(14) NOT NULL UNIQUE CHECK (cnpj ~ '^[A-Z0-9]{12}[0-9]{2}$'),
    email varchar(255) NOT NULL,
    telefone varchar(20),
    cep varchar(8) CHECK (cep ~ '^[0-9]{8}$'),
    logradouro varchar(200), numero varchar(20), complemento varchar(100),
    bairro varchar(100), cidade varchar(100), uf char(2), codigo_ibge varchar(7),
    responsavel_nome varchar(150), responsavel_email varchar(255),
    api_client_id varchar(100) UNIQUE, api_client_secret_hash varchar(255),
    api_ativo boolean NOT NULL DEFAULT false,
    api_permissao varchar(20) NOT NULL DEFAULT 'BLOQUEADO'
        CHECK (api_permissao IN ('LEITURA', 'LEITURA_ESCRITA', 'BLOQUEADO')),
    api_ultimo_acesso timestamptz,
    ativo boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    CHECK (NOT api_ativo OR (api_client_id IS NOT NULL AND api_client_secret_hash IS NOT NULL)),
    CHECK ((api_client_id IS NULL) = (api_client_secret_hash IS NULL))
);
CREATE TABLE pets (
    id_pet uuid PRIMARY KEY,
    cpf_pet varchar(20) NOT NULL UNIQUE CHECK (cpf_pet ~ '^PET-[0-9A-HJKMNP-TV-Z]{12}-[0-9]{2}$'),
    id_tutor uuid NOT NULL REFERENCES tutores,
    id_especie bigint NOT NULL REFERENCES especies,
    id_raca bigint,
    nome varchar(150) NOT NULL,
    sexo char(1) CHECK (sexo IN ('M', 'F')),
    dt_nascimento date,
    nascimento_estimado boolean NOT NULL DEFAULT false,
    cor varchar(100), pelagem varchar(100), porte varchar(20),
    castrado boolean, dt_castracao date,
    microchip varchar(50), registro_oficial varchar(100),
    peso_atual numeric(7,3) CHECK (peso_atual >= 0), tipo_sanguineo varchar(30),
    alergias text, doencas_cronicas text, medicamentos_continuos text,
    observacoes text, foto_url text,
    status varchar(20) NOT NULL DEFAULT 'ATIVO'
        CHECK (status IN ('ATIVO', 'DESAPARECIDO', 'FALECIDO', 'INATIVO')),
    dt_obito date,
    versao_tutoria bigint NOT NULL DEFAULT 0 CHECK (versao_tutoria >= 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    FOREIGN KEY (id_especie, id_raca) REFERENCES racas (id_especie, id_raca),
    CHECK (NOT nascimento_estimado OR dt_nascimento IS NOT NULL),
    CHECK (dt_obito IS NULL OR (status = 'FALECIDO' AND (dt_nascimento IS NULL OR dt_obito >= dt_nascimento))),
    CHECK (dt_castracao IS NULL OR (castrado IS TRUE AND (dt_nascimento IS NULL OR dt_castracao >= dt_nascimento)
        AND (dt_obito IS NULL OR dt_castracao <= dt_obito)))
);
CREATE INDEX pets_tutor ON pets (id_tutor, id_pet);
CREATE INDEX pets_especie_raca ON pets (id_especie, id_raca);
CREATE UNIQUE INDEX pets_microchip_unico ON pets (microchip) WHERE microchip IS NOT NULL;

CREATE OR REPLACE FUNCTION manter_cpf_pet() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.cpf_pet IS DISTINCT FROM OLD.cpf_pet THEN
        RAISE EXCEPTION 'cpf_pet é imutável' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER pets_cpf_pet_imutavel BEFORE UPDATE OF cpf_pet ON pets
    FOR EACH ROW EXECUTE FUNCTION manter_cpf_pet();

CREATE TABLE prontuario_registros (
    id_registro uuid PRIMARY KEY,
    id_pet uuid NOT NULL REFERENCES pets,
    id_tipo_procedimento bigint NOT NULL REFERENCES tipos_procedimento,
    id_registro_origem uuid,
    id_tutor_origem uuid REFERENCES tutores,
    id_empresa_origem uuid REFERENCES empresas,
    titulo varchar(200) NOT NULL, descricao text, observacoes text,
    dt_agendamento timestamptz, dt_fim_agendamento timestamptz, dt_realizacao timestamptz,
    status varchar(20) NOT NULL CHECK (status IN ('AGENDADO', 'REALIZADO', 'CANCELADO', 'NAO_REALIZADO')),
    origem varchar(20) NOT NULL CHECK (origem IN ('TUTOR', 'EMPRESA')),
    profissional_nome varchar(150), profissional_registro varchar(50), local_atendimento varchar(200),
    dados_adicionais jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    UNIQUE (id_pet, id_registro),
    FOREIGN KEY (id_pet, id_registro_origem) REFERENCES prontuario_registros (id_pet, id_registro),
    CHECK (id_registro_origem <> id_registro),
    CHECK ((status = 'REALIZADO') = (dt_realizacao IS NOT NULL)),
    CHECK (status = 'REALIZADO' OR dt_agendamento IS NOT NULL),
    CHECK (dt_fim_agendamento IS NULL OR (dt_agendamento IS NOT NULL AND dt_fim_agendamento > dt_agendamento)),
    CHECK ((origem = 'TUTOR' AND id_tutor_origem IS NOT NULL AND id_empresa_origem IS NULL)
        OR (origem = 'EMPRESA' AND id_empresa_origem IS NOT NULL AND id_tutor_origem IS NULL)),
    CHECK (dados_adicionais IS NULL OR jsonb_typeof(dados_adicionais) = 'object')
);
CREATE INDEX prontuario_realizacao ON prontuario_registros (id_pet, dt_realizacao DESC, id_registro);
CREATE INDEX prontuario_agendamento ON prontuario_registros (id_pet, dt_agendamento, id_registro);
CREATE INDEX prontuario_empresa ON prontuario_registros (id_empresa_origem);
CREATE INDEX prontuario_origem ON prontuario_registros (id_registro_origem);
CREATE TABLE anexos (
    id_anexo uuid PRIMARY KEY,
    id_registro uuid NOT NULL REFERENCES prontuario_registros,
    nome_original varchar(255) NOT NULL, nome_armazenado varchar(255) NOT NULL,
    caminho_arquivo text NOT NULL UNIQUE,
    mime_type varchar(100) NOT NULL CHECK (mime_type IN ('image/jpeg', 'image/png', 'application/pdf')),
    tamanho_bytes bigint NOT NULL CHECK (tamanho_bytes > 0),
    hash_sha256 char(64) CHECK (hash_sha256 ~ '^[a-f0-9]{64}$'),
    descricao text,
    classificacao varchar(20) NOT NULL DEFAULT 'PRIVADO'
        CHECK (classificacao IN ('PRIVADO', 'COMPARTILHAVEL', 'COPIA_OFICIAL')),
    created_at timestamptz NOT NULL DEFAULT now(), deleted_at timestamptz
);
CREATE INDEX anexos_registro ON anexos (id_registro);

CREATE TABLE auditoria (
    id_auditoria bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tabela varchar(100) NOT NULL, id_registro varchar(100) NOT NULL,
    acao varchar(30) NOT NULL,
    id_tutor uuid REFERENCES tutores, id_empresa uuid REFERENCES empresas,
    origem varchar(20) NOT NULL CHECK (origem IN ('TUTOR', 'EMPRESA', 'SISTEMA')),
    dados_anteriores jsonb, dados_novos jsonb,
    ip_origem inet, user_agent text, request_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((origem = 'TUTOR' AND id_tutor IS NOT NULL AND id_empresa IS NULL)
        OR (origem = 'EMPRESA' AND id_empresa IS NOT NULL AND id_tutor IS NULL)
        OR (origem = 'SISTEMA' AND id_empresa IS NULL AND id_tutor IS NULL))
);
CREATE INDEX auditoria_registro ON auditoria (tabela, id_registro, created_at DESC);
CREATE INDEX auditoria_empresa ON auditoria (id_empresa, created_at DESC);
