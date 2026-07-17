-- Migração 0008: compartilhamento de veículo entre contas (ex.: casal
-- dividindo o mesmo carro). O dono (veiculos.usuario_id) continua único e
-- é quem convida/remove colaboradores e edita/exclui o veículo — isso não
-- muda. veiculo_compartilhamentos é só a lista de contas ADICIONAIS que
-- passam a poder registrar/ver abastecimentos, manutenções, despesas e
-- lembretes desse veículo (histórico completo, não só dali pra frente —
-- ver comentário em includes/functions.php).
--
-- veiculo_convites espelha o padrão já usado em "convites" (convite de
-- conta): token de uso único, só o hash SHA-256 fica no banco, validade de
-- 7 dias.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0008_veiculo_compartilhamento.sql
--
-- As mesmas CREATE TABLE também foram refletidas em db/init.sql, para que
-- instalações novas (volume MySQL vazio) já subam com essas tabelas.

CREATE TABLE IF NOT EXISTS veiculo_compartilhamentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    veiculo_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_veiculo_compartilhamentos_veiculo
        FOREIGN KEY (veiculo_id) REFERENCES veiculos(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_veiculo_compartilhamentos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE KEY uq_veiculo_compartilhamentos (veiculo_id, usuario_id),
    INDEX idx_veiculo_compartilhamentos_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS veiculo_convites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    veiculo_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    criado_por INT UNSIGNED NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    usado_em DATETIME NULL,
    CONSTRAINT fk_veiculo_convites_veiculo
        FOREIGN KEY (veiculo_id) REFERENCES veiculos(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_veiculo_convites_criado_por
        FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE KEY uq_veiculo_convites_token_hash (token_hash),
    INDEX idx_veiculo_convites_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
