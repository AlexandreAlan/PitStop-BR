<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * Testa src/config/csrf.php. Não precisa de banco — só manipula $_SESSION
 * (simulado, sem sessão HTTP real; ver tests/bootstrap.php).
 */
final class CsrfTest extends TestCase
{
    #[Before]
    protected function limparSessao(): void
    {
        $_SESSION = [];
    }

    #[After]
    protected function restaurarSessao(): void
    {
        $_SESSION = [];
    }

    public function testCsrfTokenGeraTokenNaoVazio(): void
    {
        $token = csrfToken();
        $this->assertNotSame('', $token);
        $this->assertSame(64, strlen($token), 'token deve ter 32 bytes em hex (64 caracteres)');
    }

    public function testCsrfTokenReutilizaTokenJaExistenteNaSessao(): void
    {
        $primeiro = csrfToken();
        $segundo = csrfToken();
        $this->assertSame($primeiro, $segundo);
    }

    public function testCsrfValidarAceitaTokenCorreto(): void
    {
        $token = csrfToken();
        $this->assertTrue(csrfValidar($token));
    }

    public function testCsrfValidarRejeitaTokenErrado(): void
    {
        csrfToken();
        $this->assertFalse(csrfValidar('token-forjado-por-um-atacante'));
    }

    public function testCsrfValidarRejeitaNull(): void
    {
        csrfToken();
        $this->assertFalse(csrfValidar(null));
    }

    public function testCsrfValidarRejeitaQuandoNaoHaTokenNaSessao(): void
    {
        // $_SESSION já foi limpo em limparSessao() — nenhum token gerado ainda.
        $this->assertFalse(csrfValidar('qualquer-coisa'));
    }
}
