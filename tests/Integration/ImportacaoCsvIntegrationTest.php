<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Importação de histórico via CSV (caminho inverso da exportação de
 * relatorios.php) — ver analisarCsvImportacao()/validarLinhaCsvImportacao()
 * em includes/functions.php.
 */
final class ImportacaoCsvIntegrationTest extends DatabaseTestCase
{
    private const CABECALHO = "Data;Veiculo;Tipo;Combustivel/Categoria;Litros;Km;TanqueCheio;Valor (R$);Descricao";

    public function testAnalisarCsvComCabecalhoValidoRetornaLinhas(): void
    {
        $csv = self::CABECALHO . "\n01/01/2026;Moto;Abastecimento;Gasolina Comum;10,00;5000;cheio;50,00;Posto X";

        $resultado = analisarCsvImportacao($csv);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['linhas']);
    }

    public function testAnalisarCsvRejeitaCabecalhoDiferente(): void
    {
        $resultado = analisarCsvImportacao("Coluna1;Coluna2\nx;y");

        $this->assertFalse($resultado['ok']);
    }

    public function testAnalisarCsvRejeitaArquivoVazio(): void
    {
        $resultado = analisarCsvImportacao('');

        $this->assertFalse($resultado['ok']);
    }

    public function testAnalisarCsvIgnoraBomEQuebraDeLinhaWindows(): void
    {
        $csv = "\xEF\xBB\xBF" . self::CABECALHO . "\r\n01/01/2026;Moto;Manutencao;;;;;80,00;Revisao\r\n";

        $resultado = analisarCsvImportacao($csv);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['linhas']);
    }

    public function testValidarLinhaAbastecimentoValida(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $resultado = validarLinhaCsvImportacao($this->pdo, $usuarioId, $veiculoId, [
            '01/01/2026', 'Moto', 'Abastecimento', 'Gasolina Comum', '10,50', '5000', 'cheio', '50,00', 'Posto X',
        ]);

        $this->assertTrue($resultado['ok']);
        $this->assertSame('2026-01-01', $resultado['valores']['data']);
        $this->assertSame(5000, $resultado['valores']['km_atual']);
        $this->assertSame(10.5, $resultado['valores']['litros']);
        $this->assertSame(50.0, $resultado['valores']['valor_pago']);
        $this->assertTrue($resultado['valores']['tanque_cheio']);
    }

    public function testValidarLinhaComTanqueParcial(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $resultado = validarLinhaCsvImportacao($this->pdo, $usuarioId, $veiculoId, [
            '01/01/2026', 'Moto', 'Abastecimento', 'Etanol', '5,00', '5000', 'parcial', '25,00', '',
        ]);

        $this->assertTrue($resultado['ok']);
        $this->assertFalse($resultado['valores']['tanque_cheio']);
    }

    public function testValidarLinhaDespesaMapeiaCategoria(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $resultado = validarLinhaCsvImportacao($this->pdo, $usuarioId, $veiculoId, [
            '01/01/2026', 'Moto', 'Despesa', 'Seguro', '', '5000', '', '1200,00', 'Anual',
        ]);

        $this->assertTrue($resultado['ok']);
        $this->assertSame('Seguro', $resultado['valores']['categoria_despesa']);
        $this->assertNull($resultado['valores']['litros']);
    }

    public function testValidarLinhaComDataInvalidaFalha(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $resultado = validarLinhaCsvImportacao($this->pdo, $usuarioId, $veiculoId, [
            '2026-01-01', 'Moto', 'Abastecimento', 'Gasolina Comum', '10', '5000', 'cheio', '50', '',
        ]);

        $this->assertFalse($resultado['ok']);
    }

    public function testValidarLinhaComNumeroDeColunasErradoFalha(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $resultado = validarLinhaCsvImportacao($this->pdo, $usuarioId, $veiculoId, ['01/01/2026', 'Moto']);

        $this->assertFalse($resultado['ok']);
    }

    public function testValidarLinhaParaVeiculoAlheioFalha(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoDeOutro = $this->criarVeiculo($donoId);
        $atacanteId = $this->criarUsuario();

        $resultado = validarLinhaCsvImportacao($this->pdo, $atacanteId, $veiculoDeOutro, [
            '01/01/2026', 'Moto', 'Abastecimento', 'Gasolina Comum', '10', '5000', 'cheio', '50', '',
        ]);

        $this->assertFalse($resultado['ok']);
    }

    public function testFluxoCompletoImportaLinhasValidasEPulaInvalidas(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $csv = self::CABECALHO . "\n"
            . "01/01/2026;Moto;Abastecimento;Gasolina Comum;10,00;5000;cheio;50,00;Ok\n"
            . "data-invalida;Moto;Abastecimento;Gasolina Comum;10,00;5100;cheio;50,00;Falha\n"
            . "02/01/2026;Moto;Manutencao;;;5200;;120,00;Troca de oleo";

        $analise = analisarCsvImportacao($csv);
        $this->assertTrue($analise['ok']);

        $importados = 0;
        $pulados = 0;
        foreach ($analise['linhas'] as $colunas) {
            $resultado = validarLinhaCsvImportacao($this->pdo, $usuarioId, $veiculoId, $colunas);
            if ($resultado['ok']) {
                inserirRegistro($this->pdo, $resultado['valores']);
                $importados++;
            } else {
                $pulados++;
            }
        }

        $this->assertSame(2, $importados);
        $this->assertSame(1, $pulados);

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM registros')->fetchColumn();
        $this->assertSame(2, $total);
    }
}
