<?php
declare(strict_types=1);

/**
 * Retorna uma conexão PDO configurada a partir das variáveis de ambiente
 * definidas no container (ver docker-compose.yml).
 */
function getConexao(): PDO
{
    $host    = getenv('DB_HOST') ?: 'db';
    $db      = getenv('DB_NAME') ?: '';
    $user    = getenv('DB_USER') ?: '';
    $pass    = getenv('DB_PASS') ?: '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        error_log('[PitStop BR] Erro de conexão com o banco: ' . $e->getMessage());
        http_response_code(500);
        die('Erro interno ao conectar ao banco de dados. Tente novamente mais tarde.');
    }
}
