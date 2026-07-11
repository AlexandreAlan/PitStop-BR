-- Migração 0004: adiciona usuarios.sessao_valida_apos.
--
-- Auditoria de segurança 2026-07-11: não havia nenhum mecanismo de
-- revogação de sessões ativas quando a senha era trocada (redefinir_senha.php)
-- — uma sessão sequestrada (aparelho roubado/destravado) continuava válida
-- até o timeout de inatividade de 7 dias, mesmo com a senha já trocada em
-- outro aparelho.
--
-- Sessões com $_SESSION['sessao_emitida_em'] anterior a esse timestamp são
-- derrubadas no próximo request (ver config/bootstrap.php). NULL = nunca
-- trocou a senha desde que esse controle existe, nenhuma sessão afetada.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0004_sessao_valida_apos.sql
--
-- O mesmo ALTER TABLE também foi refletido no CREATE TABLE de db/init.sql,
-- para que instalações novas (volume MySQL vazio) já subam com a coluna.

ALTER TABLE usuarios
    ADD COLUMN sessao_valida_apos TIMESTAMP NULL
    AFTER meta_mensal;
