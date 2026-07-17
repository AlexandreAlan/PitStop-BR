<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Postos de combustível (favoritos, preço médio por posto) — ver
 * includes/functions.php e db/migrations/0010_postos.sql.
 */
final class PostosIntegrationTest extends DatabaseTestCase
{
    public function testCriarEListarPosto(): void
    {
        $usuarioId = $this->criarUsuario();

        $resultado = criarPosto($this->pdo, $usuarioId, 'Posto Ipiranga', 'Av. Brasil, 1000');

        $this->assertTrue($resultado['ok']);
        $lista = listarPostos($this->pdo, $usuarioId);
        $this->assertCount(1, $lista);
        $this->assertSame('Posto Ipiranga', $lista[0]['nome']);
    }

    public function testCriarPostoRejeitaNomeVazio(): void
    {
        $usuarioId = $this->criarUsuario();

        $resultado = criarPosto($this->pdo, $usuarioId, '   ', null);

        $this->assertFalse($resultado['ok']);
    }

    public function testAlternarFavoritoPosto(): void
    {
        $usuarioId = $this->criarUsuario();
        $criado = criarPosto($this->pdo, $usuarioId, 'Posto A', null);

        alternarFavoritoPosto($this->pdo, $usuarioId, $criado['id']);
        $lista = listarPostos($this->pdo, $usuarioId);
        $this->assertSame(1, (int) $lista[0]['favorito']);

        alternarFavoritoPosto($this->pdo, $usuarioId, $criado['id']);
        $lista = listarPostos($this->pdo, $usuarioId);
        $this->assertSame(0, (int) $lista[0]['favorito']);
    }

    public function testUsuarioNaoVePostoDeOutraConta(): void
    {
        $usuarioA = $this->criarUsuario();
        criarPosto($this->pdo, $usuarioA, 'Posto de A', null);

        $usuarioB = $this->criarUsuario();

        $this->assertCount(0, listarPostos($this->pdo, $usuarioB));
    }

    public function testExcluirPostoDeOutraContaNaoTemEfeito(): void
    {
        $donoId = $this->criarUsuario();
        $criado = criarPosto($this->pdo, $donoId, 'Posto do Dono', null);
        $estranhoId = $this->criarUsuario();

        $resultado = excluirPosto($this->pdo, $estranhoId, $criado['id']);

        $this->assertFalse($resultado);
        $this->assertCount(1, listarPostos($this->pdo, $donoId));
    }

    public function testExcluirPostoDeixaRegistroComPostoIdNulo(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $posto = criarPosto($this->pdo, $usuarioId, 'Posto A', null);

        $resultado = validarRegistro($this->pdo, $usuarioId, [
            'veiculo_id' => (string) $veiculoId, 'data' => '2026-01-01', 'km_atual' => '1000',
            'tipo_registro' => 'Abastecimento', 'valor_pago' => '100', 'litros' => '20',
            'combustivel' => 'Gasolina Comum', 'posto_id' => (string) $posto['id'],
        ]);
        $inserido = inserirRegistro($this->pdo, $resultado['valores']);

        excluirPosto($this->pdo, $usuarioId, $posto['id']);

        $postoIdAtual = $this->pdo->query('SELECT posto_id FROM registros WHERE id = ' . (int) $inserido['id'])->fetchColumn();
        $this->assertNull($postoIdAtual);
    }

    public function testValidarRegistroAceitaPostoProprio(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $posto = criarPosto($this->pdo, $usuarioId, 'Posto A', null);

        $resultado = validarRegistro($this->pdo, $usuarioId, [
            'veiculo_id' => (string) $veiculoId, 'data' => '2026-01-01', 'km_atual' => '1000',
            'tipo_registro' => 'Abastecimento', 'valor_pago' => '100', 'litros' => '20',
            'combustivel' => 'Gasolina Comum', 'posto_id' => (string) $posto['id'],
        ]);

        $this->assertTrue($resultado['ok']);
        $this->assertSame($posto['id'], $resultado['valores']['posto_id']);
    }

    public function testValidarRegistroRejeitaPostoDeOutraConta(): void
    {
        $usuarioA = $this->criarUsuario();
        $postoDeA = criarPosto($this->pdo, $usuarioA, 'Posto de A', null);

        $usuarioB = $this->criarUsuario();
        $veiculoDeB = $this->criarVeiculo($usuarioB);

        $resultado = validarRegistro($this->pdo, $usuarioB, [
            'veiculo_id' => (string) $veiculoDeB, 'data' => '2026-01-01', 'km_atual' => '1000',
            'tipo_registro' => 'Abastecimento', 'valor_pago' => '100', 'litros' => '20',
            'combustivel' => 'Gasolina Comum', 'posto_id' => (string) $postoDeA['id'],
        ]);

        $this->assertFalse($resultado['ok']);
    }

    public function testPrecoMedioPorPostoCalculaMediaPonderadaPorLitro(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $posto = criarPosto($this->pdo, $usuarioId, 'Posto A', null);

        // 10L por R$50 (5,00/L) e 20L por R$110 (5,50/L) => médio ponderado
        // (50+110)/(10+20) = 5,333.../L — não é a média simples dos dois preços.
        $this->inserirAbastecimentoComPosto($usuarioId, $veiculoId, $posto['id'], 1000, 10.0, 50.0);
        $this->inserirAbastecimentoComPosto($usuarioId, $veiculoId, $posto['id'], 1400, 20.0, 110.0);

        $resultado = precoMedioPorPosto($this->pdo, $usuarioId, null, null, null);

        $this->assertCount(1, $resultado);
        $this->assertEqualsWithDelta(160 / 30, $resultado[0]['preco_medio_litro'], 0.001);
        $this->assertSame(2, (int) $resultado[0]['total_abastecimentos']);
    }

    public function testPrecoMedioPorPostoIgnoraAbastecimentosSemPosto(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0); // sem posto

        $resultado = precoMedioPorPosto($this->pdo, $usuarioId, null, null, null);

        $this->assertCount(0, $resultado);
    }

    public function testPrecoMedioPorPostoNaoVazaDadosDeOutraConta(): void
    {
        $usuarioA = $this->criarUsuario();
        $veiculoDeA = $this->criarVeiculo($usuarioA);
        $postoDeA = criarPosto($this->pdo, $usuarioA, 'Posto de A', null);
        $this->inserirAbastecimentoComPosto($usuarioA, $veiculoDeA, $postoDeA['id'], 1000, 10.0, 50.0);

        $usuarioB = $this->criarUsuario();

        $this->assertCount(0, precoMedioPorPosto($this->pdo, $usuarioB, null, null, null));
    }

    private function inserirAbastecimentoComPosto(int $usuarioId, int $veiculoId, int $postoId, int $km, float $litros, float $valor): void
    {
        $resultado = validarRegistro($this->pdo, $usuarioId, [
            'veiculo_id' => (string) $veiculoId, 'data' => '2026-01-01', 'km_atual' => (string) $km,
            'tipo_registro' => 'Abastecimento', 'valor_pago' => (string) $valor, 'litros' => (string) $litros,
            'combustivel' => 'Gasolina Comum', 'posto_id' => (string) $postoId,
        ]);
        inserirRegistro($this->pdo, $resultado['valores']);
    }
}
