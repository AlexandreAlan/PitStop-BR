-- Migração 0009: foto de comprovante (nota fiscal/recibo) anexada a um
-- registro (abastecimento, manutenção ou despesa).
--
-- Guardada como BLOB no MySQL, não em arquivo no disco: o container "web"
-- roda com o filesystem read_only (ver docker-compose.yml) e só monta
-- ./src:/var/www/html:ro — não há volume gravável pra receber upload. O
-- MySQL já tem um volume persistente próprio (pitstop_db_data), então é o
-- lugar natural pra guardar esse dado sem precisar mexer na infra/hardening
-- existente. Tabela separada de "registros" (não uma coluna a mais nela)
-- pra não pesar o BLOB em toda consulta normal de listagem/relatório, que
-- nunca precisa da imagem em si.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0009_registro_fotos.sql
--
-- O mesmo CREATE TABLE também foi refletido em db/init.sql.

CREATE TABLE IF NOT EXISTS registro_fotos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registro_id INT UNSIGNED NOT NULL,
    mime_type VARCHAR(30) NOT NULL,
    tamanho_bytes INT UNSIGNED NOT NULL,
    dados MEDIUMBLOB NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_registro_fotos_registro
        FOREIGN KEY (registro_id) REFERENCES registros(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE KEY uq_registro_fotos_registro (registro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
