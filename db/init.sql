CREATE TABLE IF NOT EXISTS veiculos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registros (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    veiculo_id INT UNSIGNED NOT NULL,
    data DATE NOT NULL,
    km_atual INT UNSIGNED NOT NULL,
    tipo_registro ENUM('Abastecimento', 'Manutencao') NOT NULL,
    litros DECIMAL(6,2) NULL,
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
    CONSTRAINT chk_valores_positivos
        CHECK (valor_pago >= 0 AND (litros IS NULL OR litros > 0) AND km_atual >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO veiculos (nome, tipo) VALUES ('Honda Bros 2020', 'Moto');
