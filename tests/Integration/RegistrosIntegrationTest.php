<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Testa, contra um MySQL real, as funções de src/includes/functions.php que
 * dependem de recursos exclusivos do MySQL (window functions LAG() OVER,
 * GREATEST): cálculo de km/l e estatísticas de veículo, detecção de
 * anomalias e idempotência de inserção via client_uuid.
 */
final class RegistrosIntegrationTest extends DatabaseTestCase
{
    // --- calcularUltimaMedia() ----------------------------------------------

    public function testCalcularUltimaMediaComDoisAbastecimentos(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $this->criarAbastecimento($veiculoId, 10000, 30.0, 150.0);
        $this->criarAbastecimento($veiculoId, 10400, 32.0, 160.0);

        $media = calcularUltimaMedia($this->pdo, $usuarioId, $veiculoId);

        $this->assertSame(12.5, $media);
    }

    public function testCalcularUltimaMediaRetornaNullComUmSoAbastecimento(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $this->criarAbastecimento($veiculoId, 10000, 30.0, 150.0);

        $this->assertNull(calcularUltimaMedia($this->pdo, $usuarioId, $veiculoId));
    }

    public function testCalcularUltimaMediaRespeitaOFiltroDeVeiculo(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoA = $this->criarVeiculo($usuarioId, 'Veículo A');
        $veiculoB = $this->criarVeiculo($usuarioId, 'Veículo B');

        $this->criarAbastecimento($veiculoA, 1000, 20.0, 100.0);
        $this->criarAbastecimento($veiculoA, 1400, 20.0, 100.0); // 20 km/l

        $this->criarAbastecimento($veiculoB, 5000, 10.0, 50.0);
        $this->criarAbastecimento($veiculoB, 5100, 10.0, 50.0); // 10 km/l

        $this->assertSame(20.0, calcularUltimaMedia($this->pdo, $usuarioId, $veiculoA));
        $this->assertSame(10.0, calcularUltimaMedia($this->pdo, $usuarioId, $veiculoB));
    }

    public function testCalcularUltimaMediaNuncaVazaDadosDeOutroUsuario(): void
    {
        $usuarioA = $this->criarUsuario();
        $veiculoDeA = $this->criarVeiculo($usuarioA);
        $this->criarAbastecimento($veiculoDeA, 1000, 20.0, 100.0);
        $this->criarAbastecimento($veiculoDeA, 1400, 20.0, 100.0);

        $usuarioB = $this->criarUsuario();

        // usuarioB não tem nenhum veículo/registro — o filtro por
        // usuario_id (via JOIN) tem que zerar o resultado, mesmo pedindo
        // sem veiculo_id específico.
        $this->assertNull(calcularUltimaMedia($this->pdo, $usuarioB));
    }

    public function testCalcularUltimaMediaRetornaNullComTanquesParciais(): void
    {
        // Reproduz o caso real que motivou a v1.15.0: nenhum dos dois
        // abastecimentos encheu o tanque — sem um único tanque cheio
        // confirmado, não existe ponto de partida confiável nenhum.
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $this->criarAbastecimento($veiculoId, 15716, 7.29, 50.0, null, false);
        $this->criarAbastecimento($veiculoId, 16026, 4.49, 30.0, null, false);

        $this->assertNull(calcularUltimaMedia($this->pdo, $usuarioId, $veiculoId));
    }

    // --- calcularUltimaMediaEstimativa() ------------------------------------

    public function testCalcularUltimaMediaEstimativaFuncionaSemTanqueCheio(): void
    {
        // Mesmo caso do teste acima (nenhum tanque cheio) — a versão
        // "estimativa" não exige tanque_cheio, é o preço de dar um número
        // provisório em vez de esperar o próximo tanque cheio de verdade.
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $this->criarAbastecimento($veiculoId, 15716, 7.29, 50.0, null, false);
        $this->criarAbastecimento($veiculoId, 16026, 4.49, 30.0, null, false);

        $estimativa = calcularUltimaMediaEstimativa($this->pdo, $usuarioId, $veiculoId);

        $this->assertEqualsWithDelta(310 / 4.49, $estimativa, 0.05);
    }

    public function testCalcularUltimaMediaEstimativaRetornaNullComUmSoAbastecimento(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $this->criarAbastecimento($veiculoId, 15716, 7.29, 50.0);

        $this->assertNull(calcularUltimaMediaEstimativa($this->pdo, $usuarioId, $veiculoId));
    }

    public function testCalcularUltimaMediaEstimativaRespeitaOFiltroDeVeiculoEUsuario(): void
    {
        $usuarioA = $this->criarUsuario();
        $veiculoDeA = $this->criarVeiculo($usuarioA);
        $this->criarAbastecimento($veiculoDeA, 1000, 20.0, 100.0, null, false);
        $this->criarAbastecimento($veiculoDeA, 1400, 20.0, 100.0, null, false); // 20 km/l

        $usuarioB = $this->criarUsuario();
        $veiculoDeB = $this->criarVeiculo($usuarioB);

        $this->assertSame(20.0, calcularUltimaMediaEstimativa($this->pdo, $usuarioA, $veiculoDeA));
        // veiculoDeA pertence ao usuarioA — pedir com usuarioB não pode vazar.
        $this->assertNull(calcularUltimaMediaEstimativa($this->pdo, $usuarioB, $veiculoDeA));
    }

    // --- calcularEstatisticasVeiculo() --------------------------------------

    public function testCalcularEstatisticasVeiculoCalculaGastoKmECustoPorKm(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        $this->criarAbastecimento($veiculoId, 1400, 25.0, 125.0); // trecho: 400km / 25L = 16 km/l
        $this->criarAbastecimento($veiculoId, 1800, 20.0, 110.0); // trecho: 400km / 20L = 20 km/l

        $stats = calcularEstatisticasVeiculo($this->pdo, $usuarioId, $veiculoId, null, null);

        $this->assertSame(335.0, $stats['gasto']);
        $this->assertSame(800, $stats['km_rodado']);
        $this->assertEqualsWithDelta(335.0 / 800, $stats['custo_km'], 0.0001);
        $this->assertSame(18.0, $stats['consumo_medio']);
    }

    public function testCalcularEstatisticasVeiculoFiltraPorPeriodo(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0, '2026-01-01');
        $this->criarAbastecimento($veiculoId, 1400, 20.0, 100.0, '2026-06-01');

        $stats = calcularEstatisticasVeiculo($this->pdo, $usuarioId, $veiculoId, '2026-05-01', '2026-12-31');

        // Só o segundo abastecimento entra no recorte — sem um par anterior
        // dentro do período, não há trecho fechado nem gasto do primeiro.
        $this->assertSame(100.0, $stats['gasto']);
        $this->assertSame(0, $stats['km_rodado']);
        $this->assertNull($stats['custo_km']);
    }

    public function testCalcularEstatisticasVeiculoSemRegistrosDevolveZeros(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $stats = calcularEstatisticasVeiculo($this->pdo, $usuarioId, $veiculoId, null, null);

        $this->assertSame(0.0, $stats['gasto']);
        $this->assertSame(0, $stats['km_rodado']);
        $this->assertNull($stats['custo_km']);
        $this->assertNull($stats['consumo_medio']);
    }

    // --- inserirRegistro(): idempotência via client_uuid --------------------

    public function testInserirRegistroComMesmoClientUuidNaoDuplica(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $uuid = '11111111-1111-1111-1111-111111111111';
        $valores = $this->valoresAbastecimento($veiculoId, 1000, 20.0, 100.0);

        $primeiro = inserirRegistro($this->pdo, $valores, $uuid);
        $segundo  = inserirRegistro($this->pdo, $valores, $uuid);

        $this->assertTrue($primeiro['novo']);
        $this->assertFalse($segundo['novo']);
        $this->assertSame($primeiro['id'], $segundo['id']);

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM registros')->fetchColumn();
        $this->assertSame(1, $total);
    }

    public function testInserirRegistroSemClientUuidSempreInsereNovaLinha(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        inserirRegistro($this->pdo, $this->valoresAbastecimento($veiculoId, 1000, 20.0, 100.0));
        inserirRegistro($this->pdo, $this->valoresAbastecimento($veiculoId, 1400, 20.0, 100.0));

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM registros')->fetchColumn();
        $this->assertSame(2, $total);
    }

    // --- detectarAnomaliasRegistro() ----------------------------------------

    public function testDetectaOdometroForaDeOrdem(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $this->criarAbastecimento($veiculoId, 5000, 20.0, 100.0);

        $valores = $this->valoresAbastecimento($veiculoId, 4000, 20.0, 100.0);
        $inserido = inserirRegistro($this->pdo, $valores);
        detectarAnomaliasRegistro($this->pdo, $usuarioId, $valores, $inserido['id']);

        $alerta = $this->buscarAlerta($inserido['id'], 'odometro_inconsistente');
        $this->assertNotNull($alerta);
        $this->assertSame('atencao', $alerta['severidade']);
    }

    public function testDetectaConsumoAbaixoDoNormal(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        // Histórico estável: dois trechos de 20 km/l.
        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        $this->criarAbastecimento($veiculoId, 1400, 20.0, 100.0);
        $this->criarAbastecimento($veiculoId, 1800, 20.0, 100.0);

        // Trecho novo: 200km / 40L = 5 km/l — bem abaixo da média histórica (20 km/l).
        $valores = $this->valoresAbastecimento($veiculoId, 2000, 40.0, 100.0);
        $inserido = inserirRegistro($this->pdo, $valores);
        detectarAnomaliasRegistro($this->pdo, $usuarioId, $valores, $inserido['id']);

        $alerta = $this->buscarAlerta($inserido['id'], 'consumo_baixo');
        $this->assertNotNull($alerta);
    }

    public function testNaoDetectaConsumoAbaixoDoNormalSemHistoricoSuficiente(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        // Só 1 trecho fechado no histórico (2 abastecimentos) — não atinge o
        // mínimo de 3 trechos exigido antes de comparar.
        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);

        $valores = $this->valoresAbastecimento($veiculoId, 1050, 40.0, 100.0);
        $inserido = inserirRegistro($this->pdo, $valores);
        detectarAnomaliasRegistro($this->pdo, $usuarioId, $valores, $inserido['id']);

        $this->assertNull($this->buscarAlerta($inserido['id'], 'consumo_baixo'));
    }

    public function testDetectaPrecoAcimaDaMedia(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        // Histórico: sempre R$5,00/L.
        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        $this->criarAbastecimento($veiculoId, 1400, 20.0, 100.0);

        // Novo abastecimento: R$7,50/L — 50% acima da média (limite é 15%).
        $valores = $this->valoresAbastecimento($veiculoId, 1800, 20.0, 150.0);
        $inserido = inserirRegistro($this->pdo, $valores);
        detectarAnomaliasRegistro($this->pdo, $usuarioId, $valores, $inserido['id']);

        $alerta = $this->buscarAlerta($inserido['id'], 'preco_alto');
        $this->assertNotNull($alerta);
        $this->assertSame('info', $alerta['severidade']);
    }

    public function testNaoDetectaPrecoAcimaDaMediaDentroDoLimiar(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        $this->criarAbastecimento($veiculoId, 1400, 20.0, 100.0);

        // R$5,50/L — 10% acima da média, dentro do limiar de 15%.
        $valores = $this->valoresAbastecimento($veiculoId, 1800, 20.0, 110.0);
        $inserido = inserirRegistro($this->pdo, $valores);
        detectarAnomaliasRegistro($this->pdo, $usuarioId, $valores, $inserido['id']);

        $this->assertNull($this->buscarAlerta($inserido['id'], 'preco_alto'));
    }

    // --- calcularConquistas() ------------------------------------------------

    public function testCalcularConquistasMarcaPrimeiraCargaComUmAbastecimento(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);

        $conquistas = calcularConquistas($this->pdo, $usuarioId);

        $this->assertSame(1, $conquistas['totalAbastecimentos']);
        $this->assertSame(10, $conquistas['proximoMarco']['qtd']);

        $primeiraCarga = array_values(array_filter(
            $conquistas['badges'],
            static fn (array $b) => $b['codigo'] === 'primeira_carga'
        ))[0];
        $this->assertTrue($primeiraCarga['conquistada']);

        $dezAbastecimentos = array_values(array_filter(
            $conquistas['badges'],
            static fn (array $b) => $b['codigo'] === 'dez_abastecimentos'
        ))[0];
        $this->assertFalse($dezAbastecimentos['conquistada']);
    }

    // --- helpers --------------------------------------------------------------

    private function valoresAbastecimento(int $veiculoId, int $kmAtual, float $litros, float $valorPago): array
    {
        return [
            'veiculo_id'        => $veiculoId,
            'data'              => (new \DateTime())->format('Y-m-d'),
            'km_atual'          => $kmAtual,
            'tipo_registro'     => 'Abastecimento',
            'combustivel'       => 'Gasolina Comum',
            'litros'            => $litros,
            'categoria_despesa' => null,
            'valor_pago'        => $valorPago,
            'descricao'         => null,
        ];
    }

    private function buscarAlerta(int $registroId, string $tipo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM alertas WHERE registro_id = :registro_id AND tipo = :tipo');
        $stmt->execute([':registro_id' => $registroId, ':tipo' => $tipo]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $linha;
    }
}
