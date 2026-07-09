<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Testa validarLembrete()/inserirLembrete() (src/includes/functions.php)
 * contra um MySQL real, incluindo o CHECK constraint chk_lembrete_alvo (só
 * KM xor Data preenchido) e a idempotência via client_uuid.
 */
final class LembretesIntegrationTest extends DatabaseTestCase
{
    public function testValidarEInserirLembretePorKm(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);

        $validado = validarLembrete($this->pdo, $usuarioId, [
            'veiculo_id' => (string) $veiculoId,
            'descricao'  => 'Troca de óleo',
            'tipo_alvo'  => 'KM',
            'km_alvo'    => '15000',
        ]);
        $this->assertTrue($validado['ok']);

        $id = inserirLembrete($this->pdo, $validado['valores']);

        $linha = $this->pdo->query("SELECT tipo_alvo, km_alvo, data_alvo FROM lembretes WHERE id = {$id}")->fetch();
        $this->assertSame('KM', $linha['tipo_alvo']);
        $this->assertSame(15000, (int) $linha['km_alvo']);
        $this->assertNull($linha['data_alvo']);
    }

    public function testInserirLembreteComMesmoClientUuidNaoDuplica(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $uuid = '22222222-2222-2222-2222-222222222222';
        $valores = [
            'veiculo_id' => $veiculoId,
            'descricao'  => 'Revisão anual',
            'tipo_alvo'  => 'Data',
            'km_alvo'    => null,
            'data_alvo'  => '2026-12-01',
        ];

        $primeiroId = inserirLembrete($this->pdo, $valores, $uuid);
        $segundoId  = inserirLembrete($this->pdo, $valores, $uuid);

        $this->assertSame($primeiroId, $segundoId);
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM lembretes')->fetchColumn();
        $this->assertSame(1, $total);
    }

    public function testStatusDeLembretePorKmUsandoKmAtualRealDoVeiculo(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $this->criarAbastecimento($veiculoId, 14800, 20.0, 100.0);

        $validado = validarLembrete($this->pdo, $usuarioId, [
            'veiculo_id' => (string) $veiculoId,
            'descricao'  => 'Troca de óleo',
            'tipo_alvo'  => 'KM',
            'km_alvo'    => '15000',
        ]);
        inserirLembrete($this->pdo, $validado['valores']);

        // Mesma consulta usada em lembretes.php/relatorios.php pra montar o
        // km_atual_veiculo esperado por calcularStatusLembrete().
        $stmt = $this->pdo->query(
            "SELECT l.tipo_alvo, l.km_alvo, l.data_alvo,
                    (SELECT MAX(r.km_atual) FROM registros r WHERE r.veiculo_id = l.veiculo_id) AS km_atual_veiculo
             FROM lembretes l WHERE l.veiculo_id = {$veiculoId}"
        );
        $lembrete = $stmt->fetch();

        $status = calcularStatusLembrete($lembrete);
        $this->assertSame('proximo', $status['status']); // faltam 200km (<=500)
    }
}
