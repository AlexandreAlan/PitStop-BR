<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\SqliteFixture;

/**
 * Testa validarRegistro() (src/includes/functions.php) — a validação
 * usada tanto pelo formulário clássico (adicionar.php) quanto pela API da
 * fila offline (api/registro.php). Usa um PDO SQLite em memória (a query de
 * existência do veículo é SQL portável) só pra satisfazer o type hint —
 * ver tests/Unit/Fixtures/SqliteFixture.
 */
final class ValidarRegistroTest extends TestCase
{
    private \PDO $pdo;
    private int $usuarioId;
    private int $veiculoId;

    protected function setUp(): void
    {
        $this->pdo = SqliteFixture::criarPdo();
        $this->usuarioId = SqliteFixture::inserirUsuario($this->pdo);
        $this->veiculoId = SqliteFixture::inserirVeiculo($this->pdo, $this->usuarioId);
    }

    private function dadosValidosAbastecimento(array $sobrescrever = []): array
    {
        return $sobrescrever + [
            'veiculo_id'    => (string) $this->veiculoId,
            'data'          => '2026-07-01',
            'km_atual'      => '12345',
            'tipo_registro' => 'Abastecimento',
            'valor_pago'    => '150.00',
            'litros'        => '30.5',
            'combustivel'   => 'Gasolina Comum',
            'descricao'     => 'Posto na BR-101',
        ];
    }

    public function testAceitaAbastecimentoValido(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, $this->dadosValidosAbastecimento());

        $this->assertTrue($resultado['ok']);
        $this->assertSame([], $resultado['erros']);
        $this->assertSame($this->veiculoId, $resultado['valores']['veiculo_id']);
        $this->assertSame(30.5, $resultado['valores']['litros']);
        $this->assertSame('Gasolina Comum', $resultado['valores']['combustivel']);
        $this->assertNull($resultado['valores']['categoria_despesa']);
    }

    public function testRejeitaVeiculoSemInformar(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, $this->dadosValidosAbastecimento(['veiculo_id' => '']));

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Selecione um veículo válido.', $resultado['erros']);
    }

    public function testRejeitaVeiculoDeOutroUsuario(): void
    {
        $outroUsuarioId = SqliteFixture::inserirUsuario($this->pdo, ['email' => 'outro@example.com']);
        $veiculoDeOutro = SqliteFixture::inserirVeiculo($this->pdo, $outroUsuarioId);

        $resultado = validarRegistro(
            $this->pdo,
            $this->usuarioId,
            $this->dadosValidosAbastecimento(['veiculo_id' => (string) $veiculoDeOutro])
        );

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Veículo não encontrado.', $resultado['erros']);
    }

    public function testRejeitaVeiculoInexistente(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, $this->dadosValidosAbastecimento(['veiculo_id' => '999999']));

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Veículo não encontrado.', $resultado['erros']);
    }

    public function testRejeitaDataInvalida(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, $this->dadosValidosAbastecimento(['data' => '01/07/2026']));

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Data inválida.', $resultado['erros']);
    }

    public function testRejeitaKmNegativo(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, $this->dadosValidosAbastecimento(['km_atual' => '-10']));

        $this->assertFalse($resultado['ok']);
        $this->assertContains('KM atual inválido.', $resultado['erros']);
    }

    public function testRejeitaTipoRegistroForaDaWhitelist(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, $this->dadosValidosAbastecimento(['tipo_registro' => 'Roubo']));

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Tipo de registro inválido.', $resultado['erros']);
    }

    public function testRejeitaValorPagoNegativo(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, $this->dadosValidosAbastecimento(['valor_pago' => '-1']));

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Valor pago inválido.', $resultado['erros']);
    }

    public function testAbastecimentoSemLitrosEhInvalido(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, $this->dadosValidosAbastecimento(['litros' => '']));

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Informe os litros abastecidos.', $resultado['erros']);
    }

    public function testAbastecimentoComCombustivelForaDaWhitelistEhInvalido(): void
    {
        $resultado = validarRegistro(
            $this->pdo,
            $this->usuarioId,
            $this->dadosValidosAbastecimento(['combustivel' => 'Álcool de Posto Clandestino'])
        );

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Selecione o combustível.', $resultado['erros']);
    }

    public function testDespesaExigeCategoriaDaWhitelist(): void
    {
        $dados = [
            'veiculo_id'    => (string) $this->veiculoId,
            'data'          => '2026-07-01',
            'km_atual'      => '12345',
            'tipo_registro' => 'Despesa',
            'valor_pago'    => '50.00',
            'categoria_despesa' => 'Suborno',
        ];

        $resultado = validarRegistro($this->pdo, $this->usuarioId, $dados);

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Selecione a categoria da despesa.', $resultado['erros']);
    }

    public function testDespesaComCategoriaValidaEhAceita(): void
    {
        $dados = [
            'veiculo_id'        => (string) $this->veiculoId,
            'data'              => '2026-07-01',
            'km_atual'          => '12345',
            'tipo_registro'     => 'Despesa',
            'valor_pago'        => '50.00',
            'categoria_despesa' => 'IPVA',
        ];

        $resultado = validarRegistro($this->pdo, $this->usuarioId, $dados);

        $this->assertTrue($resultado['ok']);
        $this->assertSame('IPVA', $resultado['valores']['categoria_despesa']);
        $this->assertNull($resultado['valores']['litros']);
    }

    public function testRejeitaDescricaoMuitoLonga(): void
    {
        $resultado = validarRegistro(
            $this->pdo,
            $this->usuarioId,
            $this->dadosValidosAbastecimento(['descricao' => str_repeat('a', 256)])
        );

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Descrição muito longa (máx. 255 caracteres).', $resultado['erros']);
    }

    public function testDescricaoVaziaViraNull(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, $this->dadosValidosAbastecimento(['descricao' => '']));

        $this->assertTrue($resultado['ok']);
        $this->assertNull($resultado['valores']['descricao']);
    }

    public function testAcumulaTodosOsErrosDeUmaVez(): void
    {
        $resultado = validarRegistro($this->pdo, $this->usuarioId, [
            'veiculo_id'    => '',
            'data'          => 'não-é-data',
            'km_atual'      => 'abc',
            'tipo_registro' => 'invalido',
            'valor_pago'    => 'abc',
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertCount(5, $resultado['erros']);
    }
}
