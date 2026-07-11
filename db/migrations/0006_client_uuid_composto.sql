-- Migração 0006: uq_registros_client_uuid/uq_lembretes_client_uuid viram
-- compostas com veiculo_id.
--
-- A checagem de idempotência de client_uuid em
-- inserirRegistro()/inserirLembrete() passou a ser escopada por veículo
-- (ver includes/functions.php), então a UNIQUE KEY do banco também precisa
-- ser composta — um client_uuid só precisa ser único DENTRO do mesmo
-- veículo, que é o que garante que um reenvio da fila offline não duplica
-- a linha. Não precisa (e não devia) ser único entre veículos diferentes.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0006_client_uuid_composto.sql
--
-- O mesmo ajuste também foi refletido no CREATE TABLE de db/init.sql, para
-- que instalações novas (volume MySQL vazio) já subam com a constraint certa.

ALTER TABLE registros
    DROP INDEX uq_registros_client_uuid,
    ADD UNIQUE KEY uq_registros_client_uuid (veiculo_id, client_uuid);

ALTER TABLE lembretes
    DROP INDEX uq_lembretes_client_uuid,
    ADD UNIQUE KEY uq_lembretes_client_uuid (veiculo_id, client_uuid);
