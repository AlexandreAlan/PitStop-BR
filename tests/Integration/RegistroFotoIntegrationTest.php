<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Foto de comprovante anexada a um registro — ver includes/functions.php
 * (salvarFotoRegistro/removerFotoRegistro/buscarFotoRegistro/temFotoRegistro)
 * e db/migrations/0009_registro_fotos.sql. Cobre validação (tamanho/mime
 * real, não confiando no que o cliente alega) e isolamento entre contas.
 */
final class RegistroFotoIntegrationTest extends DatabaseTestCase
{
    // PNG 1x1 pixel válido, só pra ter bytes reais de imagem no teste.
    private const PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function testSalvarFotoValidaFuncionaEPodeSerLida(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);

        $resultado = salvarFotoRegistro($this->pdo, $usuarioId, $registroId, self::PNG_1X1_BASE64);

        $this->assertTrue($resultado['ok']);
        $this->assertTrue(temFotoRegistro($this->pdo, $usuarioId, $registroId));

        $foto = buscarFotoRegistro($this->pdo, $usuarioId, $registroId);
        $this->assertNotNull($foto);
        $this->assertSame('image/png', $foto['mime_type']);
    }

    public function testSalvarFotoAceitaDataUrlCompleta(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);

        $dataUrl = 'data:image/png;base64,' . self::PNG_1X1_BASE64;
        $resultado = salvarFotoRegistro($this->pdo, $usuarioId, $registroId, $dataUrl);

        $this->assertTrue($resultado['ok']);
    }

    public function testSalvarFotoSubstituiAAnteriorEmVezDeDuplicar(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);

        salvarFotoRegistro($this->pdo, $usuarioId, $registroId, self::PNG_1X1_BASE64);
        salvarFotoRegistro($this->pdo, $usuarioId, $registroId, self::PNG_1X1_BASE64);

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM registro_fotos')->fetchColumn();
        $this->assertSame(1, $total);
    }

    public function testRejeitaConteudoQueNaoEhImagemDeVerdade(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);

        // Um .txt disfarçado de imagem (base64 de texto puro) — a validação
        // real é pelo conteúdo (finfo), nunca pelo que o cliente alega.
        $resultado = salvarFotoRegistro($this->pdo, $usuarioId, $registroId, base64_encode('isso nao e uma imagem'));

        $this->assertFalse($resultado['ok']);
        $this->assertFalse(temFotoRegistro($this->pdo, $usuarioId, $registroId));
    }

    public function testRejeitaFotoMaiorQueOLimite(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);

        // > 900KB decodificado, mesmo que fosse uma imagem válida.
        $grande = base64_encode(str_repeat('a', 1_000_000));
        $resultado = salvarFotoRegistro($this->pdo, $usuarioId, $registroId, $grande);

        $this->assertFalse($resultado['ok']);
    }

    public function testEstranhoNaoConsegueAnexarFotoEmRegistroAlheio(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        $estranhoId = $this->criarUsuario();

        $resultado = salvarFotoRegistro($this->pdo, $estranhoId, $registroId, self::PNG_1X1_BASE64);

        $this->assertFalse($resultado['ok']);
        $this->assertFalse(temFotoRegistro($this->pdo, $donoId, $registroId));
    }

    public function testEstranhoNaoConsegueLerFotoDeRegistroAlheio(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        salvarFotoRegistro($this->pdo, $donoId, $registroId, self::PNG_1X1_BASE64);

        $estranhoId = $this->criarUsuario();

        $this->assertNull(buscarFotoRegistro($this->pdo, $estranhoId, $registroId));
        $this->assertFalse(temFotoRegistro($this->pdo, $estranhoId, $registroId));
    }

    public function testColaboradorDoVeiculoConsegueLerAFoto(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        salvarFotoRegistro($this->pdo, $donoId, $registroId, self::PNG_1X1_BASE64);

        $convidadoId = $this->criarUsuario();
        $token = criarConviteVeiculo($this->pdo, $donoId, $veiculoId, 'colab@example.com');
        aceitarConviteVeiculo($this->pdo, (string) $token, $convidadoId);

        $this->assertTrue(temFotoRegistro($this->pdo, $convidadoId, $registroId));
        $this->assertNotNull(buscarFotoRegistro($this->pdo, $convidadoId, $registroId));
    }

    public function testRemoverFotoApagaERemocaoPorEstranhoNaoTemEfeito(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        salvarFotoRegistro($this->pdo, $donoId, $registroId, self::PNG_1X1_BASE64);

        $estranhoId = $this->criarUsuario();
        removerFotoRegistro($this->pdo, $estranhoId, $registroId);
        $this->assertTrue(temFotoRegistro($this->pdo, $donoId, $registroId));

        removerFotoRegistro($this->pdo, $donoId, $registroId);
        $this->assertFalse(temFotoRegistro($this->pdo, $donoId, $registroId));
    }

    public function testExcluirRegistroRemoveAFotoJunto(): void
    {
        $usuarioId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($usuarioId);
        $registroId = $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        salvarFotoRegistro($this->pdo, $usuarioId, $registroId, self::PNG_1X1_BASE64);

        $this->pdo->prepare('DELETE FROM registros WHERE id = :id')->execute([':id' => $registroId]);

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM registro_fotos')->fetchColumn();
        $this->assertSame(0, $total);
    }
}
