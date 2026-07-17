<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTime;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base para testes que precisam de um MySQL real — cobrem a parte da lógica
 * de negócio que depende de recursos exclusivos do MySQL (window functions,
 * DATE_FORMAT, GREATEST, SELECT ... FOR UPDATE) e por isso não dá pra testar
 * com o SQLite em memória usado em tests/Unit (ver SqliteFixture).
 *
 * Espera um banco de teste já com o schema de db/init.sql aplicado, apontado
 * pelas mesmas variáveis de ambiente usadas em produção (ver
 * src/config/conexao.php): DB_HOST, DB_NAME, DB_USER, DB_PASS. Sem DB_HOST
 * definida, os testes desta suíte são pulados (ver docs/TESTES.md).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped(
                'DB_HOST não definida — testes de integração exigem um MySQL de teste. Ver docs/TESTES.md.'
            );
        }

        $db   = (string) getenv('DB_NAME');
        $user = (string) getenv('DB_USER');
        $pass = (string) getenv('DB_PASS');

        $this->pdo = new PDO(
            "mysql:host={$host};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        // Isola cada teste: esvazia as tabelas de domínio antes de cada
        // execução (FK com ON DELETE CASCADE cuida do resto a partir de
        // usuarios/veiculos). Tabelas de catálogo (modelos_veiculos) e de
        // infraestrutura de auth não usadas nesta suíte ficam de fora.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['alertas', 'veiculo_passaportes', 'veiculo_convites', 'veiculo_compartilhamentos', 'registros', 'lembretes', 'push_inscricoes', 'verificacoes_email', 'redefinicoes_senha', 'convites', 'veiculos', 'usuarios'] as $tabela) {
            $this->pdo->exec("TRUNCATE TABLE {$tabela}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function criarUsuario(array $sobrescrever = []): int
    {
        $dados = $sobrescrever + [
            'nome'                => 'Usuária Teste',
            'email'               => 'usuaria+' . bin2hex(random_bytes(4)) . '@example.com',
            'senha_hash'          => password_hash('Senha@123', PASSWORD_DEFAULT),
            'email_verificado_em' => (new DateTime())->format('Y-m-d H:i:s'),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash, email_verificado_em)
             VALUES (:nome, :email, :senha_hash, :email_verificado_em)'
        );
        $stmt->execute([
            ':nome'                => $dados['nome'],
            ':email'               => $dados['email'],
            ':senha_hash'          => $dados['senha_hash'],
            ':email_verificado_em' => $dados['email_verificado_em'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    protected function criarVeiculo(int $usuarioId, string $nome = 'Moto Teste', string $tipo = 'Moto'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO veiculos (usuario_id, nome, tipo) VALUES (:usuario_id, :nome, :tipo)');
        $stmt->execute([':usuario_id' => $usuarioId, ':nome' => $nome, ':tipo' => $tipo]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insere um registro de Abastecimento direto via SQL (sem passar por
     * validarRegistro/inserirRegistro), pra montar o histórico que os testes
     * de cálculo (km/l, estatísticas, anomalias) precisam como ponto de
     * partida. $tanqueCheio null = usa o DEFAULT da coluna (1/cheio).
     */
    protected function criarAbastecimento(int $veiculoId, int $kmAtual, float $litros, float $valorPago, ?string $data = null, ?bool $tanqueCheio = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registros (veiculo_id, data, km_atual, tipo_registro, combustivel, litros, valor_pago' . ($tanqueCheio !== null ? ', tanque_cheio' : '') . ')
             VALUES (:veiculo_id, :data, :km_atual, :tipo_registro, :combustivel, :litros, :valor_pago' . ($tanqueCheio !== null ? ', :tanque_cheio' : '') . ')'
        );
        $params = [
            ':veiculo_id'    => $veiculoId,
            ':data'          => $data ?? (new DateTime())->format('Y-m-d'),
            ':km_atual'      => $kmAtual,
            ':tipo_registro' => 'Abastecimento',
            ':combustivel'   => 'Gasolina Comum',
            ':litros'        => $litros,
            ':valor_pago'    => $valorPago,
        ];
        if ($tanqueCheio !== null) {
            $params[':tanque_cheio'] = $tanqueCheio ? 1 : 0;
        }
        $stmt->execute($params);

        return (int) $this->pdo->lastInsertId();
    }
}
