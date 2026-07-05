CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    tentativas_falhas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME NULL,
    aceite_privacidade_em DATETIME NULL,
    meta_mensal DECIMAL(10,2) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modelos_veiculos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    marca VARCHAR(60) NOT NULL,
    modelo VARCHAR(80) NOT NULL,
    ano_inicio SMALLINT UNSIGNED NOT NULL,
    ano_fim SMALLINT UNSIGNED NULL,
    tanque_litros DECIMAL(5,2) NULL,
    peso_kg SMALLINT UNSIGNED NULL,
    consumo_cidade_kml DECIMAL(4,1) NULL,
    consumo_estrada_kml DECIMAL(4,1) NULL,
    INDEX idx_modelos_busca (marca, modelo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS veiculos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    nome VARCHAR(100) NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    cor VARCHAR(30) NULL,
    placa VARCHAR(8) NULL,
    modelo_veiculo_id INT UNSIGNED NULL,
    tanque_litros DECIMAL(5,2) NULL,
    peso_kg SMALLINT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_veiculos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_veiculos_modelo
        FOREIGN KEY (modelo_veiculo_id) REFERENCES modelos_veiculos(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    INDEX idx_veiculos_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS convites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    criado_por INT UNSIGNED NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    usado_em DATETIME NULL,
    CONSTRAINT fk_convites_criado_por
        FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE KEY uq_convites_token_hash (token_hash),
    INDEX idx_convites_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redefinicoes_senha (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    usado_em DATETIME NULL,
    CONSTRAINT fk_redefinicoes_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE KEY uq_redefinicoes_token_hash (token_hash),
    INDEX idx_redefinicoes_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redefinicao_rate_limit (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_redefinicao_rate_limit_ip (ip_hash, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O bloqueio em usuarios.tentativas_falhas protege só UMA conta por vez;
-- sem isso, um IP conseguiria tentar senhas contra centenas de contas
-- diferentes sem nunca ser freado (credential stuffing).
CREATE TABLE IF NOT EXISTS login_rate_limit (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_rate_limit_ip (ip_hash, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registros (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    veiculo_id INT UNSIGNED NOT NULL,
    data DATE NOT NULL,
    km_atual INT UNSIGNED NOT NULL,
    tipo_registro ENUM('Abastecimento', 'Manutencao', 'Despesa') NOT NULL,
    combustivel ENUM('Gasolina Comum', 'Gasolina Aditivada', 'Etanol', 'Diesel', 'GNV', 'Outro') NULL,
    litros DECIMAL(6,2) NULL,
    categoria_despesa ENUM('Seguro', 'IPVA', 'Estacionamento', 'Pedagio', 'Multa', 'Lavagem', 'Outro') NULL,
    valor_pago DECIMAL(10,2) NOT NULL,
    descricao VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_registros_veiculo
        FOREIGN KEY (veiculo_id) REFERENCES veiculos(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    INDEX idx_registros_veiculo_km (veiculo_id, km_atual),
    INDEX idx_registros_tipo (tipo_registro),
    CONSTRAINT chk_litros_abastecimento
        CHECK (tipo_registro <> 'Abastecimento' OR litros IS NOT NULL),
    CONSTRAINT chk_combustivel_abastecimento
        CHECK (tipo_registro <> 'Abastecimento' OR combustivel IS NOT NULL),
    CONSTRAINT chk_categoria_despesa
        CHECK (tipo_registro <> 'Despesa' OR categoria_despesa IS NOT NULL),
    CONSTRAINT chk_valores_positivos
        CHECK (valor_pago >= 0 AND (litros IS NULL OR litros > 0) AND km_atual >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lembretes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    veiculo_id INT UNSIGNED NOT NULL,
    descricao VARCHAR(150) NOT NULL,
    tipo_alvo ENUM('KM', 'Data') NOT NULL,
    km_alvo INT UNSIGNED NULL,
    data_alvo DATE NULL,
    concluido_em TIMESTAMP NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_lembretes_veiculo
        FOREIGN KEY (veiculo_id) REFERENCES veiculos(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    INDEX idx_lembretes_veiculo (veiculo_id),
    CONSTRAINT chk_lembrete_alvo
        CHECK (
            (tipo_alvo = 'KM' AND km_alvo IS NOT NULL AND data_alvo IS NULL) OR
            (tipo_alvo = 'Data' AND data_alvo IS NOT NULL AND km_alvo IS NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de modelos pra autopreencher tanque/peso/consumo de fábrica ao
-- cadastrar um veículo. Ponto de partida com os modelos mais comuns no
-- Brasil — cresce aos poucos por INSERT direto (não tem tela de admin
-- pra isso ainda), valores aproximados de ficha técnica do fabricante.
INSERT INTO modelos_veiculos (tipo, marca, modelo, ano_inicio, ano_fim, tanque_litros, peso_kg, consumo_cidade_kml, consumo_estrada_kml) VALUES
('Moto', 'Honda', 'CG 160 Fan/Start/Titan', 2016, 2023, 16.0, 148, 30.0, 35.0),
('Moto', 'Honda', 'CG 160', 2024, NULL, 16.0, 150, 31.0, 36.0),
('Moto', 'Honda', 'NXR 160 Bros', 2016, 2020, 14.0, 152, 28.0, 32.0),
('Moto', 'Honda', 'NXR 160 Bros', 2021, NULL, 14.0, 154, 29.0, 33.0),
('Moto', 'Honda', 'Biz 125', 2019, NULL, 5.3, 104, 42.0, 48.0),
('Moto', 'Honda', 'PCX 150', 2019, NULL, 8.0, 131, 33.0, 38.0),
('Moto', 'Yamaha', 'Factor 150', 2019, NULL, 16.0, 137, 35.0, 40.0),
('Moto', 'Yamaha', 'Fazer 250', 2019, NULL, 16.0, 157, 28.0, 32.0),
('Moto', 'Yamaha', 'XTZ 150 Crosser', 2019, NULL, 16.0, 138, 30.0, 35.0),
('Carro', 'Volkswagen', 'Gol 1.0', 2017, 2023, 55.0, 1023, 10.5, 13.5),
('Carro', 'Volkswagen', 'Polo 1.0', 2018, NULL, 52.0, 1090, 11.0, 14.5),
('Carro', 'Volkswagen', 'Saveiro 1.6', 2018, NULL, 55.0, 1210, 9.0, 12.0),
('Carro', 'Fiat', 'Mobi 1.0', 2017, NULL, 47.0, 940, 12.0, 15.5),
('Carro', 'Fiat', 'Uno 1.0', 2015, 2021, 48.0, 995, 11.5, 14.8),
('Carro', 'Fiat', 'Strada 1.4', 2018, 2020, 60.0, 1160, 10.0, 13.0),
('Carro', 'Chevrolet', 'Onix 1.0', 2020, NULL, 44.0, 1043, 12.5, 15.8),
('Carro', 'Chevrolet', 'Onix Plus 1.0', 2020, NULL, 44.0, 1060, 12.5, 15.8),
('Carro', 'Renault', 'Kwid 1.0', 2018, NULL, 39.0, 897, 13.0, 16.5),
('Carro', 'Hyundai', 'HB20 1.0', 2020, NULL, 50.0, 1040, 12.0, 15.5),
('Carro', 'Toyota', 'Corolla 2.0', 2020, NULL, 50.0, 1330, 10.0, 13.5),
('Carro', 'Honda', 'Civic 2.0', 2017, 2021, 47.0, 1340, 9.5, 13.0);
