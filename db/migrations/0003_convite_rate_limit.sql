-- Migração 0003: tabela convite_rate_limit.
--
-- Limita quantos convites uma conta pode enviar por hora — mesmo objetivo
-- de login_rate_limit/cadastro_rate_limit (evitar abuso em volume), mas
-- por usuario_id em vez de IP, já que o endpoint exige login.
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
