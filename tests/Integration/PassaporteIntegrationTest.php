<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * "Passaporte do veículo": link público (sem login), read-only, com o
 * histórico completo de um veículo — ver includes/functions.php e
 * db/migrations/0007_veiculo_passaportes.sql. Cobre especialmente o
 * isolamento entre veículos/contas (nunca vazar dado de um veículo através
 * do token de outro) e o ciclo gerar/rotacionar/revogar.
 */
final class PassaporteIntegrationTest extends DatabaseTestCase
{
    public function testGerarPassaporteCriaTokenValido(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $token = criarOuRotacionarPassaporte($this->pdo, $usuarioId, $veiculoId);

        $this->assertNotNull($token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertTrue(passaporteAtivo($this->pdo, $usuarioId, $veiculoId));
    }

    public function testGerarPassaporteParaVeiculoDeOutroUsuarioRetornaNull(): void
    {
        $dono = $this->criarUsuario();
        $veiculoDeOutro = $this->criarVeiculo($dono);

        $atacante = $this->criarUsuario();

        $this->assertNull(criarOuRotacionarPassaporte($this->pdo, $atacante, $veiculoDeOutro));
        $this->assertFalse(passaporteAtivo($this->pdo, $atacante, $veiculoDeOutro));
        // O dono de verdade continua sem link nenhum — a tentativa do
        // atacante não pode ter criado um passaporte por baixo dos panos.
        $this->assertFalse(passaporteAtivo($this->pdo, $dono, $veiculoDeOutro));
    }

    public function testBuscarVeiculoPorTokenPassaporteResolveCorretamente(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $token = criarOuRotacionarPassaporte($this->pdo, $usuarioId, $veiculoId);

        $contexto = buscarVeiculoPorTokenPassaporte($this->pdo, (string) $token);

        $this->assertNotNull($contexto);
        $this->assertSame($veiculoId, $contexto['veiculo_id']);
        $this->assertSame($usuarioId, $contexto['usuario_id']);
    }

    public function testBuscarVeiculoPorTokenInvalidoRetornaNull(): void
    {
        $this->assertNull(buscarVeiculoPorTokenPassaporte($this->pdo, 'token-nao-hexadecimal'));
        $this->assertNull(buscarVeiculoPorTokenPassaporte($this->pdo, str_repeat('a', 64))); // formato válido, mas não existe
    }

    public function testRotacionarTokenInvalidaOTokenAnterior(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $tokenAntigo = criarOuRotacionarPassaporte($this->pdo, $usuarioId, $veiculoId);
        $tokenNovo   = criarOuRotacionarPassaporte($this->pdo, $usuarioId, $veiculoId);

        $this->assertNotSame($tokenAntigo, $tokenNovo);
        $this->assertNull(buscarVeiculoPorTokenPassaporte($this->pdo, (string) $tokenAntigo));
        $this->assertNotNull(buscarVeiculoPorTokenPassaporte($this->pdo, (string) $tokenNovo));

        // Ainda só 1 linha (UPDATE, não um INSERT acumulando histórico).
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM veiculo_passaportes')->fetchColumn();
        $this->assertSame(1, $total);
    }

    public function testRevogarPassaporteInvalidaOToken(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $token = criarOuRotacionarPassaporte($this->pdo, $usuarioId, $veiculoId);

        $revogado = revogarPassaporte($this->pdo, $usuarioId, $veiculoId);

        $this->assertTrue($revogado);
        $this->assertNull(buscarVeiculoPorTokenPassaporte($this->pdo, (string) $token));
        $this->assertFalse(passaporteAtivo($this->pdo, $usuarioId, $veiculoId));
    }

    public function testRevogarPassaporteDeOutroUsuarioNaoTemEfeito(): void
    {
        $dono = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($dono);
        $token = criarOuRotacionarPassaporte($this->pdo, $dono, $veiculoId);

        $atacante = $this->criarUsuario();
        $revogado = revogarPassaporte($this->pdo, $atacante, $veiculoId);

        $this->assertFalse($revogado);
        // Link do dono de verdade continua funcionando.
        $this->assertNotNull(buscarVeiculoPorTokenPassaporte($this->pdo, (string) $token));
    }

    public function testTokenDeUmVeiculoNaoResolveDadosDeOutroVeiculo(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoA = $this->criarVeiculo($usuarioId, 'Veículo A');
        $veiculoB = $this->criarVeiculo($usuarioId, 'Veículo B');

        $tokenA = criarOuRotacionarPassaporte($this->pdo, $usuarioId, $veiculoA);

        $contexto = buscarVeiculoPorTokenPassaporte($this->pdo, (string) $tokenA);

        $this->assertSame($veiculoA, $contexto['veiculo_id']);
        $this->assertNotSame($veiculoB, $contexto['veiculo_id']);
    }

    public function testExcluirVeiculoRemoveOPassaporteJunto(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $token = criarOuRotacionarPassaporte($this->pdo, $usuarioId, $veiculoId);

        $this->pdo->prepare('DELETE FROM veiculos WHERE id = :id')->execute([':id' => $veiculoId]);

        $this->assertNull(buscarVeiculoPorTokenPassaporte($this->pdo, (string) $token));
    }
}
