<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

use DateTime;
use PDO;

/**
 * PDO SQLite em memória com um recorte mínimo do schema real (ver
 * db/init.sql) — só as tabelas/colunas que as funções cobertas nestes testes
 * consultam. Permite testar a lógica de validação/negócio dessas funções sem
 * subir um MySQL de verdade.
 *
 * Só serve pra código cuja SQL é portável entre MySQL e SQLite (sem window
 * functions, DATE_FORMAT, GREATEST ou SELECT ... FOR UPDATE) — o que depende
 * disso continua coberto só em tests/Integration contra um MySQL real (ver
 * DatabaseTestCase).
 *
 * NOW() é reimplementada aqui como função SQL custom só pra as poucas
 * queries que a usam (ex.: registrarUsuario) rodarem sem alterar o código
 * de produção.
 */
final class SqliteFixture
{
    public static function criarPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => (new DateTime())->format('Y-m-d H:i:s'));

        $pdo->exec(
            'CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                senha_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "user",
                tentativas_falhas INTEGER NOT NULL DEFAULT 0,
                bloqueado_ate TEXT NULL,
                aceite_privacidade_em TEXT NULL,
                email_verificado_em TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE veiculos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                usuario_id INTEGER NOT NULL,
                nome TEXT NOT NULL,
                tipo TEXT NOT NULL
            )'
        );

        return $pdo;
    }

    public static function inserirUsuario(PDO $pdo, array $dados = []): int
    {
        $dados += [
            'nome'                  => 'Usuária Teste',
            'email'                 => 'usuaria@example.com',
            'senha_hash'            => password_hash('Senha@123', PASSWORD_DEFAULT),
            'role'                  => 'user',
            'tentativas_falhas'     => 0,
            'bloqueado_ate'         => null,
            'email_verificado_em'   => (new DateTime())->format('Y-m-d H:i:s'),
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash, role, tentativas_falhas, bloqueado_ate, email_verificado_em)
             VALUES (:nome, :email, :senha_hash, :role, :tentativas_falhas, :bloqueado_ate, :email_verificado_em)'
        );
        $stmt->execute([
            ':nome'                => $dados['nome'],
            ':email'               => $dados['email'],
            ':senha_hash'          => $dados['senha_hash'],
            ':role'                => $dados['role'],
            ':tentativas_falhas'   => $dados['tentativas_falhas'],
            ':bloqueado_ate'       => $dados['bloqueado_ate'],
            ':email_verificado_em' => $dados['email_verificado_em'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function inserirVeiculo(PDO $pdo, int $usuarioId, string $nome = 'Moto Teste', string $tipo = 'Moto'): int
    {
        $stmt = $pdo->prepare('INSERT INTO veiculos (usuario_id, nome, tipo) VALUES (:usuario_id, :nome, :tipo)');
        $stmt->execute([':usuario_id' => $usuarioId, ':nome' => $nome, ':tipo' => $tipo]);

        return (int) $pdo->lastInsertId();
    }
}
