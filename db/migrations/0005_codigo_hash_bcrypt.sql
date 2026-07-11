-- Migração 0005: verificacoes_email.codigo_hash vira VARCHAR(255).
--
-- O código de verificação de e-mail (6 dígitos) passa a ser guardado com
-- password_hash() (bcrypt) em vez de sha256 puro, mesmo padrão já usado em
-- usuarios.senha_hash — daí o VARCHAR(255) (bcrypt gera ~60 chars, mas o
-- algoritmo padrão do PHP pode mudar, então segue o mesmo tamanho já usado
-- pra senha).
--
-- CHAR(64) → VARCHAR(255) é seguro mesmo com códigos antigos já gravados no
-- formato hexadecimal de 64 chars: eles não batem mais com nenhum código
-- novo gerado (todo código tem só AUTH_CODIGO_VALIDADE_MINUTOS de validade
-- e é apagado após uso ou substituído a cada novo pedido), então não há
-- necessidade de migrar dado existente, só o schema.
--
-- Este projeto ainda não tem um runner de migrações — aplique manualmente:
--   docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < db/migrations/0005_codigo_hash_bcrypt.sql
--
-- O mesmo ALTER TABLE também foi refletido no CREATE TABLE de db/init.sql,
-- para que instalações novas (volume MySQL vazio) já subam com a coluna certa.

ALTER TABLE verificacoes_email
    MODIFY COLUMN codigo_hash VARCHAR(255) NOT NULL;
