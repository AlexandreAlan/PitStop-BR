<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\SqliteFixture;

/**
 * Testa loginUsuario() (src/config/auth.php): credenciais erradas, bloqueio
 * progressivo por tentativas falhas, conta bloqueada e exigência de e-mail
 * verificado. O caminho de sucesso chama session_regenerate_id(), que exige
 * uma sessão ativa — por isso a sessão é iniciada aqui via session_start()
 * (funciona normalmente em CLI, sem precisar de headers HTTP).
 */
final class LoginUsuarioTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $this->pdo = SqliteFixture::criarPdo();
    }

    public function testRejeitaEmailInexistenteComMensagemGenerica(): void
    {
        $resultado = loginUsuario($this->pdo, 'ninguem@example.com', 'qualquer-senha');

        $this->assertFalse($resultado['ok']);
        $this->assertSame('E-mail ou senha inválidos.', $resultado['erro']);
    }

    public function testRejeitaSenhaErradaComMensagemGenerica(): void
    {
        SqliteFixture::inserirUsuario($this->pdo, ['email' => 'usuaria@example.com']);

        $resultado = loginUsuario($this->pdo, 'usuaria@example.com', 'senha-errada');

        $this->assertFalse($resultado['ok']);
        // Mesma mensagem do e-mail inexistente: não revela qual dos dois
        // estava errado (evita enumeração de contas).
        $this->assertSame('E-mail ou senha inválidos.', $resultado['erro']);
    }

    public function testIncrementaTentativasFalhasAposSenhaErrada(): void
    {
        $id = SqliteFixture::inserirUsuario($this->pdo, ['email' => 'usuaria@example.com']);

        loginUsuario($this->pdo, 'usuaria@example.com', 'senha-errada');

        $tentativas = (int) $this->pdo->query("SELECT tentativas_falhas FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertSame(1, $tentativas);
    }

    public function testBloqueiaContaAposCincoTentativasFalhas(): void
    {
        $id = SqliteFixture::inserirUsuario($this->pdo, ['email' => 'usuaria@example.com', 'tentativas_falhas' => 4]);

        loginUsuario($this->pdo, 'usuaria@example.com', 'senha-errada');

        $linha = $this->pdo->query("SELECT tentativas_falhas, bloqueado_ate FROM usuarios WHERE id = {$id}")->fetch();
        $this->assertSame(5, (int) $linha['tentativas_falhas']);
        $this->assertNotNull($linha['bloqueado_ate']);
    }

    public function testRejeitaContaBloqueada(): void
    {
        $bloqueadoAte = (new \DateTime('+10 minutes'))->format('Y-m-d H:i:s');
        SqliteFixture::inserirUsuario($this->pdo, ['email' => 'usuaria@example.com', 'bloqueado_ate' => $bloqueadoAte]);

        $resultado = loginUsuario($this->pdo, 'usuaria@example.com', 'Senha@123');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('bloqueada', $resultado['erro']);
    }

    public function testAceitaLoginComBloqueioJaExpirado(): void
    {
        $bloqueadoAte = (new \DateTime('-10 minutes'))->format('Y-m-d H:i:s');
        SqliteFixture::inserirUsuario($this->pdo, ['email' => 'usuaria@example.com', 'bloqueado_ate' => $bloqueadoAte]);

        $resultado = loginUsuario($this->pdo, 'usuaria@example.com', 'Senha@123');

        $this->assertTrue($resultado['ok']);
    }

    public function testLoginComSucessoZeraTentativasFalhasEAbreSessao(): void
    {
        $id = SqliteFixture::inserirUsuario($this->pdo, ['email' => 'usuaria@example.com', 'tentativas_falhas' => 3]);

        $resultado = loginUsuario($this->pdo, 'usuaria@example.com', 'Senha@123');

        $this->assertTrue($resultado['ok']);
        $this->assertArrayNotHasKey('precisaVerificar', $resultado);
        $this->assertSame($id, $_SESSION['usuario_id']);
        $this->assertSame('user', $_SESSION['usuario_role']);

        $tentativas = (int) $this->pdo->query("SELECT tentativas_falhas FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertSame(0, $tentativas);
    }

    public function testLoginComEmailNaoVerificadoNaoAbreSessaoEPedeVerificacao(): void
    {
        $id = SqliteFixture::inserirUsuario($this->pdo, ['email' => 'usuaria@example.com', 'email_verificado_em' => null]);

        $resultado = loginUsuario($this->pdo, 'usuaria@example.com', 'Senha@123');

        $this->assertTrue($resultado['ok']);
        $this->assertTrue($resultado['precisaVerificar']);
        $this->assertArrayNotHasKey('usuario_id', $_SESSION);
        $this->assertSame($id, $_SESSION['verificacao_pendente_id']);
    }
}
