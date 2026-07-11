-- Migração 0003: tabela convite_rate_limit.
--
-- Auditoria de segurança 2026-07-11: convidar.php não tinha rate limit —
-- uma conta autenticada conseguia usá-lo como oráculo de enumeração de
-- e-mails cadastrados (resposta explícita revelava "já existe conta com
-- esse e-mail") e mandar volume ilimitado de e-mails de "convite" pra
-- endereços arbitrários, abusando do SMTP de produção pra spam.
--
-- Por usuario_id (quem está convidando), não por IP: o endpoint já exige
-- login, então o identificador mais direto é a própria conta.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0003_convite_rate_limit.sql
--
-- O mesmo CREATE TABLE também foi adicionado ao db/init.sql, para que
-- instalações novas (volume MySQL vazio) já subam com a tabela.

CREATE TABLE IF NOT EXISTS convite_rate_limit (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_convite_rate_limit_usuario (usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
