<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Benchmark anônimo de consumo ("como você está vs a média") — ver
 * calcularBenchmarkConsumo() em includes/functions.php. Cobre a
 * segmentação por tipo+combustível, o limiar mínimo de amostra
 * (k-anonimato) e que o retorno nunca expõe dado por veículo/conta, só
 * agregado.
 */
final class BenchmarkConsumoIntegrationTest extends DatabaseTestCase
{
    public function testRetornaNullSemConsumoProprioCalculavel(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId, 'Moto', 'Moto');
        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0); // só 1 — sem trecho fechado

        $this->assertNull(calcularBenchmarkConsumo($this->pdo, $usuarioId, $veiculoId));
    }

    public function testRetornaNullComAmostraInsuficiente(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId, 'Moto', 'Moto');
        $this->fecharUmTrecho($veiculoId, 20.0);

        // Só 2 outros veículos no segmento — abaixo do mínimo de 5.
        for ($i = 0; $i < 2; $i++) {
            $outroUsuario = $this->criarUsuario();
            $outroVeiculo = $this->criarVeiculo($outroUsuario, 'Moto ' . $i, 'Moto');
            $this->fecharUmTrecho($outroVeiculo, 25.0);
        }

        $this->assertNull(calcularBenchmarkConsumo($this->pdo, $usuarioId, $veiculoId));
    }

    public function testCalculaMediaComAmostraSuficiente(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId, 'Moto', 'Moto');
        $this->fecharUmTrecho($veiculoId, 30.0); // 30 km/l

        // 5 outros veículos, todos Moto/Gasolina Comum, consumo médio 20 km/l.
        for ($i = 0; $i < 5; $i++) {
            $outroUsuario = $this->criarUsuario();
            $outroVeiculo = $this->criarVeiculo($outroUsuario, 'Moto ' . $i, 'Moto');
            $this->fecharUmTrecho($outroVeiculo, 20.0);
        }

        $resultado = calcularBenchmarkConsumo($this->pdo, $usuarioId, $veiculoId);

        $this->assertNotNull($resultado);
        $this->assertSame(30.0, $resultado['seu_consumo']);
        $this->assertSame(20.0, $resultado['media_outros']);
        $this->assertSame(5, $resultado['amostra']);
        $this->assertSame(50, $resultado['diferenca_percentual']); // 30 é 50% acima de 20
        $this->assertSame(100, $resultado['percentil']); // melhor que todos os 5 outros
        // Retorno só tem dado agregado — nunca uma lista/detalhe por veículo.
        $this->assertArrayNotHasKey('veiculos', $resultado);
        $this->assertArrayNotHasKey('outros', $resultado);
    }

    public function testNaoMisturaTiposDeVeiculoDiferentes(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId, 'Moto', 'Moto');
        $this->fecharUmTrecho($veiculoId, 30.0);

        // 5 carros (tipo diferente) não podem contar como amostra da moto.
        for ($i = 0; $i < 5; $i++) {
            $outroUsuario = $this->criarUsuario();
            $outroVeiculo = $this->criarVeiculo($outroUsuario, 'Carro ' . $i, 'Carro');
            $this->fecharUmTrecho($outroVeiculo, 12.0);
        }

        $this->assertNull(calcularBenchmarkConsumo($this->pdo, $usuarioId, $veiculoId));
    }

    public function testNaoMisturaCombustiveisDiferentes(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId, 'Carro', 'Carro');
        $this->fecharUmTrecho($veiculoId, 12.0, 'Gasolina Comum');

        // 5 carros a Etanol não entram na amostra de um carro a Gasolina.
        for ($i = 0; $i < 5; $i++) {
            $outroUsuario = $this->criarUsuario();
            $outroVeiculo = $this->criarVeiculo($outroUsuario, 'Carro Etanol ' . $i, 'Carro');
            $this->fecharUmTrecho($outroVeiculo, 8.0, 'Etanol');
        }

        $this->assertNull(calcularBenchmarkConsumo($this->pdo, $usuarioId, $veiculoId));
    }

    public function testNaoContaOutroVeiculoDoMesmoDonoNaAmostra(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId, 'Moto A', 'Moto');
        $this->fecharUmTrecho($veiculoId, 30.0);

        // Mesma conta tem outra moto — não pode contar como "outro".
        $segundaMoto = $this->criarVeiculo($usuarioId, 'Moto B', 'Moto');
        $this->fecharUmTrecho($segundaMoto, 25.0);

        for ($i = 0; $i < 4; $i++) {
            $outroUsuario = $this->criarUsuario();
            $outroVeiculo = $this->criarVeiculo($outroUsuario, 'Moto ' . $i, 'Moto');
            $this->fecharUmTrecho($outroVeiculo, 20.0);
        }

        // 4 outros de fato + a segunda moto do mesmo dono (que não deveria
        // contar) = ainda abaixo do mínimo de 5 se a exclusão funcionar certo.
        $this->assertNull(calcularBenchmarkConsumo($this->pdo, $usuarioId, $veiculoId));
    }

    public function testEstranhoNaoConsegueBenchmarkDeVeiculoAlheio(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId, 'Moto', 'Moto');
        $this->fecharUmTrecho($veiculoId, 30.0);

        $estranhoId = $this->criarUsuario();

        $this->assertNull(calcularBenchmarkConsumo($this->pdo, $estranhoId, $veiculoId));
    }

    /** Fecha um único trecho de tanque-cheio-a-tanque-cheio com o km/l exato informado. */
    private function fecharUmTrecho(int $veiculoId, float $kml, string $combustivel = 'Gasolina Comum'): void
    {
        $litros = 10.0;
        $km = (int) round($litros * $kml);

        $stmt = $this->pdo->prepare(
            'INSERT INTO registros (veiculo_id, data, km_atual, tipo_registro, combustivel, litros, tanque_cheio, valor_pago)
             VALUES (:veiculo_id, :data, 0, "Abastecimento", :combustivel, :litros, 1, 50)'
        );
        $stmt->execute([':veiculo_id' => $veiculoId, ':data' => '2026-01-01', ':combustivel' => $combustivel, ':litros' => $litros]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO registros (veiculo_id, data, km_atual, tipo_registro, combustivel, litros, tanque_cheio, valor_pago)
             VALUES (:veiculo_id, :data, :km, "Abastecimento", :combustivel, :litros, 1, 50)'
        );
        $stmt->execute([':veiculo_id' => $veiculoId, ':data' => '2026-01-02', ':km' => $km, ':combustivel' => $combustivel, ':litros' => $litros]);
    }
}
