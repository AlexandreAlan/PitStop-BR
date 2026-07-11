-- Migração 0002: campo tanque_cheio em registros.
--
-- O cálculo de km/l ("cheio a cheio": km rodado desde o abastecimento
-- anterior ÷ litros do abastecimento atual) assumia implicitamente que TODO
-- abastecimento enchia o tanque por completo. Um complemento parcial (tanque
-- não cheio) quebra essa conta e gera consumo absurdo (ex.: 69 km/l numa
-- moto) — ver calcularTrechosConsumo() em functions.php.
--
-- DEFAULT 1 (cheio) preserva o comportamento atual pra todo o histórico já
-- existente — só passa a fazer diferença em abastecimentos novos ou quando
-- o usuário corrigir um registro antigo manualmente.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0002_tanque_cheio.sql
--
-- O mesmo ALTER TABLE também foi refletido no CREATE TABLE de db/init.sql,
-- para que instalações novas (volume MySQL vazio) já subam com a coluna.

ALTER TABLE registros
    ADD COLUMN tanque_cheio TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Só relevante p/ Abastecimento: encheu o tanque (1) ou foi complemento parcial (0). Usado em calcularTrechosConsumo() pra não computar km/l sobre um abastecimento parcial isolado.'
    AFTER litros;
