<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\SqliteFixture;

/**
 * Testa validarLembrete() (src/includes/functions.php), usada pelo
 * formulário clássico (lembretes.php) e pela API da fila offline
 * (api/lembrete.php). Ver ValidarRegistroTest sobre o uso do PDO SQLite.
 */
final class ValidarLembreteTest extends TestCase
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

    public function testAceitaLembretePorKmValido(): void
    {
        $resultado = validarLembrete($this->pdo, $this->usuarioId, [
            'veiculo_id' => (string) $this->veiculoId,
            'descricao'  => 'Troca de óleo',
            'tipo_alvo'  => 'KM',
            'km_alvo'    => '15000',
        ]);

        $this->assertTrue($resultado['ok']);
        $this->assertSame(15000, $resultado['valores']['km_alvo']);
        $this->assertNull($resultado['valores']['data_alvo']);
    }

    public function testAceitaLembretePorDataValida(): void
    {
        $resultado = validarLembrete($this->pdo, $this->usuarioId, [
            'veiculo_id' => (string) $this->veiculoId,
            'descricao'  => 'Revisão anual',
            'tipo_alvo'  => 'Data',
            'data_alvo'  => '2026-12-01',
        ]);

        $this->assertTrue($resultado['ok']);
        $this->assertSame('2026-12-01', $resultado['valores']['data_alvo']);
        $this->assertNull($resultado['valores']['km_alvo']);
    }

    public function testRejeitaVeiculoInexistente(): void
    {
        $resultado = validarLembrete($this->pdo, $this->usuarioId, [
            'veiculo_id' => '999999',
            'descricao'  => 'Troca de óleo',
            'tipo_alvo'  => 'KM',
            'km_alvo'    => '15000',
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Veículo não encontrado.', $resultado['erros']);
    }

    public function testRejeitaDescricaoVazia(): void
    {
        $resultado = validarLembrete($this->pdo, $this->usuarioId, [
            'veiculo_id' => (string) $this->veiculoId,
            'descricao'  => '',
            'tipo_alvo'  => 'KM',
            'km_alvo'    => '15000',
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Descrição inválida (máx. 150 caracteres).', $resultado['erros']);
    }

    public function testRejeitaDescricaoMuitoLonga(): void
    {
        $resultado = validarLembrete($this->pdo, $this->usuarioId, [
            'veiculo_id' => (string) $this->veiculoId,
            'descricao'  => str_repeat('a', 151),
            'tipo_alvo'  => 'KM',
            'km_alvo'    => '15000',
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Descrição inválida (máx. 150 caracteres).', $resultado['erros']);
    }

    public function testRejeitaTipoAlvoForaDaWhitelist(): void
    {
        $resultado = validarLembrete($this->pdo, $this->usuarioId, [
            'veiculo_id' => (string) $this->veiculoId,
            'descricao'  => 'Troca de óleo',
            'tipo_alvo'  => 'Semana',
            'km_alvo'    => '15000',
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Selecione se o lembrete é por km ou por data.', $resultado['erros']);
    }

    public function testLembretePorKmSemKmAlvoEhInvalido(): void
    {
        $resultado = validarLembrete($this->pdo, $this->usuarioId, [
            'veiculo_id' => (string) $this->veiculoId,
            'descricao'  => 'Troca de óleo',
            'tipo_alvo'  => 'KM',
            'km_alvo'    => '',
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Informe o km alvo do lembrete.', $resultado['erros']);
    }

    public function testLembretePorKmComZeroEhInvalido(): void
    {
        // min_range é 1: km_alvo = 0 não faz sentido como alvo de manutenção.
        $resultado = validarLembrete($this->pdo, $this->usuarioId, [
            'veiculo_id' => (string) $this->veiculoId,
            'descricao'  => 'Troca de óleo',
            'tipo_alvo'  => 'KM',
            'km_alvo'    => '0',
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Informe o km alvo do lembrete.', $resultado['erros']);
    }

    public function testLembretePorDataComFormatoInvalidoEhInvalido(): void
    {
        $resultado = validarLembrete($this->pdo, $this->usuarioId, [
            'veiculo_id' => (string) $this->veiculoId,
            'descricao'  => 'Revisão anual',
            'tipo_alvo'  => 'Data',
            'data_alvo'  => '01/12/2026',
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Informe uma data alvo válida.', $resultado['erros']);
    }
}
