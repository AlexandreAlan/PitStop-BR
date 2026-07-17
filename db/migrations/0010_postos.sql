-- Migração 0010: postos de combustível (nome + localização opcional,
-- marcação de favorito) e vínculo opcional de um registro a um posto —
-- permite comparar preço médio por posto ao longo do tempo em Relatórios.
--
-- Postos são pessoais (usuario_id de quem cadastrou), não compartilhados
-- entre contas que dividem um veículo (ver migração 0008) — é uma lista de
-- "onde eu costumo abastecer", não um dado do veículo em si. Cada
-- colaborador mantém a própria lista de postos, mesmo registrando no mesmo
-- veículo compartilhado.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0010_postos.sql
--
-- As mesmas alterações também foram refletidas em db/init.sql.

CREATE TABLE IF NOT EXISTS postos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    nome VARCHAR(100) NOT NULL,
    localizacao VARCHAR(255) NULL,
    favorito TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_postos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    INDEX idx_postos_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE registros
    ADD COLUMN posto_id INT UNSIGNED NULL AFTER combustivel,
    ADD CONSTRAINT fk_registros_posto
        FOREIGN KEY (posto_id) REFERENCES postos(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    ADD INDEX idx_registros_posto (posto_id);
