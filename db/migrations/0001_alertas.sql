-- Migração 0001: tabela de alertas inteligentes (detecção de anomalias de
-- consumo/preço/odômetro a partir do histórico de registros).
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0001_alertas.sql
--
-- O mesmo CREATE TABLE também foi adicionado ao db/init.sql, para que
-- instalações novas (volume MySQL vazio) já subam com a tabela.

CREATE TABLE IF NOT EXISTS alertas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    veiculo_id INT UNSIGNED NOT NULL,
    tipo ENUM('consumo_baixo', 'preco_alto', 'odometro_inconsistente') NOT NULL,
    severidade ENUM('info', 'atencao', 'critico') NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    mensagem VARCHAR(255) NOT NULL,
    registro_id INT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lido_em TIMESTAMP NULL,
    -- Marca quando o push desse alerta já foi mandado, mesmo padrão de
    -- lembretes.push_notificado_em — evita reenvio a cada execução do cron.
    push_notificado_em TIMESTAMP NULL,
    CONSTRAINT fk_alertas_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_alertas_veiculo
        FOREIGN KEY (veiculo_id) REFERENCES veiculos(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_alertas_registro
        FOREIGN KEY (registro_id) REFERENCES registros(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    INDEX idx_alertas_usuario_nao_lidos (usuario_id, lido_em),
    INDEX idx_alertas_push_pendente (push_notificado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
