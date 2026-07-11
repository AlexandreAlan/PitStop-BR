-- Migração 0004: adiciona usuarios.sessao_valida_apos.
--
-- Permite revogar sessões ativas quando a senha é trocada
-- (redefinir_senha.php): sessões com $_SESSION['sessao_emitida_em']
-- anterior a esse timestamp são derrubadas no próximo request (ver
-- checarRevogacaoDeSessao() em config/auth.php, chamada em
-- config/bootstrap.php). NULL = nunca trocou a senha desde que esse
-- controle existe, nenhuma sessão afetada.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0004_sessao_valida_apos.sql
--
-- O mesmo ALTER TABLE também foi refletido no CREATE TABLE de db/init.sql,
-- para que instalações novas (volume MySQL vazio) já subam com a coluna.

ALTER TABLE usuarios
    ADD COLUMN sessao_valida_apos TIMESTAMP NULL
    AFTER meta_mensal;
