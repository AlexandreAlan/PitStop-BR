CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    tentativas_falhas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME NULL,
    aceite_privacidade_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS veiculos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    nome VARCHAR(100) NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_veiculos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
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
