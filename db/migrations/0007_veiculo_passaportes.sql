-- Migração 0007: "Passaporte do veículo" — link público (sem login), read-only,
-- com o histórico completo de um veículo específico, pra o dono provar
-- procedência na hora de vender. Um veículo tem no máximo 1 link ativo por
-- vez: "gerar novo link" sobrescreve o token anterior (UPDATE, não INSERT),
-- e "revogar" apaga a linha — sem versionamento de tokens antigos, o
-- objetivo é só "qual é o link válido agora", nunca uma lista de histórico.
--
-- Só o hash SHA-256 do token é armazenado (mesmo padrão de convites/
-- redefinição de senha) — o token puro só existe no link entregue ao dono,
-- nunca no banco.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0007_veiculo_passaportes.sql
--
-- O mesmo CREATE TABLE também foi refletido em db/init.sql, para que
-- instalações novas (volume MySQL vazio) já subam com esta tabela.

CREATE TABLE IF NOT EXISTS veiculo_passaportes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    veiculo_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    criado_por INT UNSIGNED NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_veiculo_passaportes_veiculo
        FOREIGN KEY (veiculo_id) REFERENCES veiculos(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_veiculo_passaportes_criado_por
        FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE KEY uq_veiculo_passaportes_veiculo (veiculo_id),
    UNIQUE KEY uq_veiculo_passaportes_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
