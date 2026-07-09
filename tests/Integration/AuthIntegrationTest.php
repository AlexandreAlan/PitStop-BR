<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTime;

/**
 * Testa, contra um MySQL real, os fluxos de src/config/auth.php que usam
 * NOW() ou SELECT ... FOR UPDATE (não suportados pelo SQLite in-memory
 * usado em tests/Unit — ver RegistrarUsuarioTest/LoginUsuarioTest para a
 * cobertura de validação/lockout que não depende disso).
 */
final class AuthIntegrationTest extends DatabaseTestCase
{
    public function testRegistrarUsuarioInsereLinhaComAceiteDePrivacidadeCarimbado(): void
    {
        $resultado = registrarUsuario($this->pdo, 'Maria Silva', 'maria@example.com', 'SenhaForte123', true);

        $this->assertTrue($resultado['ok']);

        $linha = $this->pdo->query("SELECT email, aceite_privacidade_em, email_verificado_em FROM usuarios WHERE id = {$resultado['id']}")->fetch();
        $this->assertSame('maria@example.com', $linha['email']);
        $this->assertNotNull($linha['aceite_privacidade_em']);
        // Cadastro público começa não verificado — só gerarCodigoVerificacao +
        // verificarCodigoEmail (fluxo abaixo) confirma o e-mail.
        $this->assertNull($linha['email_verificado_em']);
    }

    public function testFluxoDeVerificacaoDeEmailComCodigoCorreto(): void
    {
        $usuarioId = $this->criarUsuario(['email_verificado_em' => null]);

        $codigo = gerarCodigoVerificacao($this->pdo, $usuarioId);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $codigo);

        $resultado = verificarCodigoEmail($this->pdo, $usuarioId, $codigo);
        $this->assertTrue($resultado['ok']);

        $verificadoEm = $this->pdo->query("SELECT email_verificado_em FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();
        $this->assertNotNull($verificadoEm);

        // Confirmado: o código não pode ser reaproveitado.
        $restantes = (int) $this->pdo->query('SELECT COUNT(*) FROM verificacoes_email')->fetchColumn();
        $this->assertSame(0, $restantes);
    }

    public function testVerificarCodigoEmailRejeitaCodigoErradoEContaTentativa(): void
    {
        $usuarioId = $this->criarUsuario(['email_verificado_em' => null]);
        gerarCodigoVerificacao($this->pdo, $usuarioId);

        $resultado = verificarCodigoEmail($this->pdo, $usuarioId, '000000');

        $this->assertFalse($resultado['ok']);
        $this->assertSame('Código incorreto.', $resultado['erro']);

        $tentativas = (int) $this->pdo->query('SELECT tentativas FROM verificacoes_email')->fetchColumn();
        $this->assertSame(1, $tentativas);
    }

    public function testVerificarCodigoEmailRejeitaAposExpirar(): void
    {
        $usuarioId = $this->criarUsuario(['email_verificado_em' => null]);
        $codigo = gerarCodigoVerificacao($this->pdo, $usuarioId);

        $this->pdo->prepare('UPDATE verificacoes_email SET expira_em = :expira WHERE usuario_id = :id')
            ->execute([':expira' => (new DateTime('-1 minute'))->format('Y-m-d H:i:s'), ':id' => $usuarioId]);

        $resultado = verificarCodigoEmail($this->pdo, $usuarioId, $codigo);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('Código expirado. Peça um novo código.', $resultado['erro']);
    }

    public function testGerarNovoCodigoInvalidaOAnterior(): void
    {
        $usuarioId = $this->criarUsuario(['email_verificado_em' => null]);
        gerarCodigoVerificacao($this->pdo, $usuarioId);
        $novoCodigo = gerarCodigoVerificacao($this->pdo, $usuarioId);

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM verificacoes_email')->fetchColumn();
        $this->assertSame(1, $total, 'só o código mais recente deve continuar valendo');

        $resultado = verificarCodigoEmail($this->pdo, $usuarioId, $novoCodigo);
        $this->assertTrue($resultado['ok']);
    }

    public function testRedefinirSenhaComTokenValido(): void
    {
        $usuarioId = $this->criarUsuario();
        $token = gerarTokenRedefinicaoSenha($this->pdo, $usuarioId);

        $resultado = redefinirSenhaComToken($this->pdo, $token, 'NovaSenhaForte123');

        $this->assertTrue($resultado['ok']);

        $hash = $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();
        $this->assertTrue(password_verify('NovaSenhaForte123', $hash));

        $usadoEm = $this->pdo->query('SELECT usado_em FROM redefinicoes_senha')->fetchColumn();
        $this->assertNotNull($usadoEm);
    }

    public function testRedefinirSenhaComTokenJaUsadoFalha(): void
    {
        $usuarioId = $this->criarUsuario();
        $token = gerarTokenRedefinicaoSenha($this->pdo, $usuarioId);
        redefinirSenhaComToken($this->pdo, $token, 'PrimeiraSenhaForte1');

        $resultado = redefinirSenhaComToken($this->pdo, $token, 'SegundaSenhaForte2');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('inválido', $resultado['erro']);
    }

    public function testRedefinirSenhaComTokenExpiradoFalha(): void
    {
        $usuarioId = $this->criarUsuario();
        $token = gerarTokenRedefinicaoSenha($this->pdo, $usuarioId);
        $this->pdo->prepare('UPDATE redefinicoes_senha SET expira_em = :expira WHERE usuario_id = :id')
            ->execute([':expira' => (new DateTime('-1 minute'))->format('Y-m-d H:i:s'), ':id' => $usuarioId]);

        $resultado = redefinirSenhaComToken($this->pdo, $token, 'NovaSenhaForte123');

        $this->assertFalse($resultado['ok']);
    }

    public function testRedefinirSenhaRejeitaTokenInexistente(): void
    {
        $resultado = redefinirSenhaComToken($this->pdo, 'token-que-nunca-existiu', 'NovaSenhaForte123');

        $this->assertFalse($resultado['ok']);
    }

    public function testLoginUsuarioComSucessoContraSchemaReal(): void
    {
        $usuarioId = $this->criarUsuario(['email' => 'login-real@example.com']);

        $resultado = loginUsuario($this->pdo, 'login-real@example.com', 'Senha@123');

        $this->assertTrue($resultado['ok']);
        $this->assertSame($usuarioId, $_SESSION['usuario_id']);
    }
}
