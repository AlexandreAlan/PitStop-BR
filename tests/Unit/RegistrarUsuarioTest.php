<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\SqliteFixture;

/**
 * Testa registrarUsuario() (src/config/auth.php).
 */
final class RegistrarUsuarioTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteFixture::criarPdo();
    }

    public function testRegistraComSucesso(): void
    {
        $resultado = registrarUsuario($this->pdo, 'Maria Silva', 'maria@example.com', 'SenhaForte123', true);

        $this->assertTrue($resultado['ok']);
        $this->assertIsInt($resultado['id']);
        $this->assertSame('Maria Silva', $resultado['nome']);

        $linha = $this->pdo->query('SELECT email, senha_hash FROM usuarios WHERE id = ' . $resultado['id'])->fetch();
        $this->assertSame('maria@example.com', $linha['email']);
        $this->assertTrue(password_verify('SenhaForte123', $linha['senha_hash']));
    }

    public function testNormalizaEmailParaMinusculoETrim(): void
    {
        $resultado = registrarUsuario($this->pdo, 'Maria Silva', '  MARIA@Example.COM  ', 'SenhaForte123', true);

        $linha = $this->pdo->query('SELECT email FROM usuarios WHERE id = ' . $resultado['id'])->fetch();
        $this->assertSame('maria@example.com', $linha['email']);
    }

    public function testRejeitaNomeSemSobrenome(): void
    {
        $resultado = registrarUsuario($this->pdo, 'Maria', 'maria@example.com', 'SenhaForte123', true);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('Informe nome e sobrenome.', $resultado['erro']);
    }

    public function testRejeitaNomeVazio(): void
    {
        $resultado = registrarUsuario($this->pdo, '   ', 'maria@example.com', 'SenhaForte123', true);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('Informe seu nome (máx. 100 caracteres).', $resultado['erro']);
    }

    public function testRejeitaEmailInvalido(): void
    {
        $resultado = registrarUsuario($this->pdo, 'Maria Silva', 'nao-e-email', 'SenhaForte123', true);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('E-mail inválido.', $resultado['erro']);
    }

    public function testRejeitaSenhaCurta(): void
    {
        $resultado = registrarUsuario($this->pdo, 'Maria Silva', 'maria@example.com', '1234567', true);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('pelo menos 8 caracteres', $resultado['erro']);
    }

    public function testRejeitaSemAceiteDePrivacidade(): void
    {
        $resultado = registrarUsuario($this->pdo, 'Maria Silva', 'maria@example.com', 'SenhaForte123', false);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('Política de Privacidade', $resultado['erro']);
    }

    public function testNaoRevelaQueEmailJaExiste(): void
    {
        SqliteFixture::inserirUsuario($this->pdo, ['email' => 'ja-existe@example.com']);

        $resultado = registrarUsuario($this->pdo, 'Outra Pessoa', 'ja-existe@example.com', 'SenhaForte123', true);

        // Sentinela interna ('email_existente'), nunca um texto exibido na
        // tela — ver comentário de registrarUsuario() sobre enumeração de
        // contas.
        $this->assertFalse($resultado['ok']);
        $this->assertSame('email_existente', $resultado['erro']);
    }

    public function testEmailDuplicadoNaoInsereSegundaLinha(): void
    {
        SqliteFixture::inserirUsuario($this->pdo, ['email' => 'ja-existe@example.com']);

        registrarUsuario($this->pdo, 'Outra Pessoa', 'ja-existe@example.com', 'SenhaForte123', true);

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        $this->assertSame(1, $total);
    }
}
