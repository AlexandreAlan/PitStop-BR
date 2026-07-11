-- Migração 0006: uq_registros_client_uuid/uq_lembretes_client_uuid viram
-- compostas com veiculo_id.
--
-- Auditoria de segurança 2026-07-11: a checagem de idempotência de
-- client_uuid em inserirRegistro()/inserirLembrete() foi escopada pelo
-- dono do veículo (evita que um client_uuid de outro usuário "ache" o
-- registro dele em vez de criar um novo). Mas a UNIQUE KEY do banco era só
-- em client_uuid, global — mesmo com a query da aplicação já escopada
-- corretamente, o INSERT de dois usuários diferentes usando o mesmo uuid
-- (client_uuid é gerado no cliente, um usuário mal-intencionado pode
-- forçar qualquer valor) ainda batia de frente na constraint e falhava com
-- erro de integridade.
--
-- Um client_uuid só precisa ser único DENTRO do mesmo veículo — é só isso
-- que garante que um reenvio da fila offline não duplica a linha. Não
-- precisa (e não devia) ser único entre usuários/veículos diferentes.
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
