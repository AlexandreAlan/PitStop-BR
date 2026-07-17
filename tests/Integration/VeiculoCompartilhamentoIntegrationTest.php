<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Compartilhamento de veículo entre contas (ex.: casal dividindo o mesmo
 * carro) — ver includes/functions.php e
 * db/migrations/0008_veiculo_compartilhamento.sql. Cobre o ciclo completo
 * (convidar → aceitar → acesso concedido → remover/sair) e, principalmente,
 * o isolamento: uma conta não convidada nunca pode ver/mexer no veículo de
 * outra só porque ela é dona de OUTRO veículo.
 */
final class VeiculoCompartilhamentoIntegrationTest extends DatabaseTestCase
{
    public function testDonoSempreTemAcesso(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);

        $this->assertTrue(usuarioTemAcessoVeiculo($this->pdo, $donoId, $veiculoId));
    }

    public function testUsuarioSemRelacaoNaoTemAcesso(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $estranhoId = $this->criarUsuario();

        $this->assertFalse(usuarioTemAcessoVeiculo($this->pdo, $estranhoId, $veiculoId));
    }

    public function testFluxoCompletoDeConviteConcedeAcesso(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $convidadoId = $this->criarUsuario();

        $token = criarConviteVeiculo($this->pdo, $donoId, $veiculoId, 'convidado@example.com');
        $this->assertNotNull($token);
        $this->assertFalse(usuarioTemAcessoVeiculo($this->pdo, $convidadoId, $veiculoId));

        $veiculoAceito = aceitarConviteVeiculo($this->pdo, (string) $token, $convidadoId);

        $this->assertSame($veiculoId, $veiculoAceito);
        $this->assertTrue(usuarioTemAcessoVeiculo($this->pdo, $convidadoId, $veiculoId));
    }

    public function testConvidarVeiculoQueNaoEhSeuRetornaNull(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $naoDonoId = $this->criarUsuario();

        $token = criarConviteVeiculo($this->pdo, $naoDonoId, $veiculoId, 'x@example.com');

        $this->assertNull($token);
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM veiculo_convites')->fetchColumn();
        $this->assertSame(0, $total);
    }

    public function testConviteUsadoNaoPodeSerReusado(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $convidadoId = $this->criarUsuario();
        $outroUsuarioId = $this->criarUsuario();

        $token = criarConviteVeiculo($this->pdo, $donoId, $veiculoId, 'convidado@example.com');
        aceitarConviteVeiculo($this->pdo, (string) $token, $convidadoId);

        $segundaTentativa = aceitarConviteVeiculo($this->pdo, (string) $token, $outroUsuarioId);

        $this->assertNull($segundaTentativa);
        $this->assertFalse(usuarioTemAcessoVeiculo($this->pdo, $outroUsuarioId, $veiculoId));
    }

    public function testConviteExpiradoNaoPodeSerAceito(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $convidadoId = $this->criarUsuario();

        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare(
            'INSERT INTO veiculo_convites (veiculo_id, email, token_hash, criado_por, expira_em)
             VALUES (:veiculo_id, :email, :token_hash, :criado_por, :expira_em)'
        );
        $stmt->execute([
            ':veiculo_id' => $veiculoId,
            ':email'      => 'convidado@example.com',
            ':token_hash' => hash('sha256', $token),
            ':criado_por' => $donoId,
            ':expira_em'  => '2000-01-01 00:00:00',
        ]);

        $resultado = aceitarConviteVeiculo($this->pdo, $token, $convidadoId);

        $this->assertNull($resultado);
        $this->assertFalse(usuarioTemAcessoVeiculo($this->pdo, $convidadoId, $veiculoId));
    }

    public function testColaboradorVeHistoricoCompletoAnteriorAoConvite(): void
    {
        // Decisão de produto documentada em includes/functions.php: o
        // colaborador enxerga o histórico INTEIRO, não só dali pra frente.
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $this->criarAbastecimento($veiculoId, 1000, 20.0, 100.0);
        $this->criarAbastecimento($veiculoId, 1400, 20.0, 100.0); // fecha trecho: 20 km/l

        $convidadoId = $this->criarUsuario();
        $token = criarConviteVeiculo($this->pdo, $donoId, $veiculoId, 'convidado@example.com');
        aceitarConviteVeiculo($this->pdo, (string) $token, $convidadoId);

        // O colaborador consegue calcular a mesma média que o dono, incluindo
        // os abastecimentos registrados ANTES dele entrar.
        $this->assertSame(20.0, calcularUltimaMedia($this->pdo, $convidadoId, $veiculoId));

        $veiculosDoConvidado = veiculosAcessiveis($this->pdo, $convidadoId);
        $this->assertCount(1, $veiculosDoConvidado);
        $this->assertFalse($veiculosDoConvidado[0]['e_dono']);
    }

    public function testColaboradorPodeRegistrarEEditarNoVeiculoCompartilhado(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $convidadoId = $this->criarUsuario();
        $token = criarConviteVeiculo($this->pdo, $donoId, $veiculoId, 'convidado@example.com');
        aceitarConviteVeiculo($this->pdo, (string) $token, $convidadoId);

        $resultado = validarRegistro($this->pdo, $convidadoId, [
            'veiculo_id'    => (string) $veiculoId,
            'data'          => (new \DateTime())->format('Y-m-d'),
            'km_atual'      => '1000',
            'tipo_registro' => 'Abastecimento',
            'valor_pago'    => '100',
            'litros'        => '20',
            'combustivel'   => 'Gasolina Comum',
        ]);

        $this->assertTrue($resultado['ok']);
    }

    public function testEstranhoNaoPodeRegistrarNoVeiculoAlheio(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $estranhoId = $this->criarUsuario();

        $resultado = validarRegistro($this->pdo, $estranhoId, [
            'veiculo_id'    => (string) $veiculoId,
            'data'          => (new \DateTime())->format('Y-m-d'),
            'km_atual'      => '1000',
            'tipo_registro' => 'Abastecimento',
            'valor_pago'    => '100',
            'litros'        => '20',
            'combustivel'   => 'Gasolina Comum',
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertContains('Veículo não encontrado.', $resultado['erros']);
    }

    public function testDonoPodeRemoverColaborador(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $convidadoId = $this->criarUsuario();
        $token = criarConviteVeiculo($this->pdo, $donoId, $veiculoId, 'convidado@example.com');
        aceitarConviteVeiculo($this->pdo, (string) $token, $convidadoId);

        $removido = removerCompartilhamentoVeiculo($this->pdo, $donoId, $veiculoId, $convidadoId);

        $this->assertTrue($removido);
        $this->assertFalse(usuarioTemAcessoVeiculo($this->pdo, $convidadoId, $veiculoId));
        // Dono continua com acesso normal.
        $this->assertTrue(usuarioTemAcessoVeiculo($this->pdo, $donoId, $veiculoId));
    }

    public function testColaboradorPodeSairPorContaPropria(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $convidadoId = $this->criarUsuario();
        $token = criarConviteVeiculo($this->pdo, $donoId, $veiculoId, 'convidado@example.com');
        aceitarConviteVeiculo($this->pdo, (string) $token, $convidadoId);

        $saiu = removerCompartilhamentoVeiculo($this->pdo, $convidadoId, $veiculoId, $convidadoId);

        $this->assertTrue($saiu);
        $this->assertFalse(usuarioTemAcessoVeiculo($this->pdo, $convidadoId, $veiculoId));
    }

    public function testOutroColaboradorNaoPodeRemoverColaboradorAlheio(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);
        $convidadoA = $this->criarUsuario();
        $convidadoB = $this->criarUsuario();

        aceitarConviteVeiculo($this->pdo, (string) criarConviteVeiculo($this->pdo, $donoId, $veiculoId, 'a@example.com'), $convidadoA);
        aceitarConviteVeiculo($this->pdo, (string) criarConviteVeiculo($this->pdo, $donoId, $veiculoId, 'b@example.com'), $convidadoB);

        // convidadoA tenta remover convidadoB — não é dono nem é ele mesmo.
        $resultado = removerCompartilhamentoVeiculo($this->pdo, $convidadoA, $veiculoId, $convidadoB);

        $this->assertFalse($resultado);
        $this->assertTrue(usuarioTemAcessoVeiculo($this->pdo, $convidadoB, $veiculoId));
    }

    public function testCompartilhamentoDeUmVeiculoNaoVazaAcessoAOutroVeiculoDoMesmoDono(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoCompartilhado = $this->criarVeiculo($donoId, 'Compartilhado');
        $veiculoPrivado = $this->criarVeiculo($donoId, 'Privado');
        $convidadoId = $this->criarUsuario();

        $token = criarConviteVeiculo($this->pdo, $donoId, $veiculoCompartilhado, 'convidado@example.com');
        aceitarConviteVeiculo($this->pdo, (string) $token, $convidadoId);

        $this->assertTrue(usuarioTemAcessoVeiculo($this->pdo, $convidadoId, $veiculoCompartilhado));
        $this->assertFalse(usuarioTemAcessoVeiculo($this->pdo, $convidadoId, $veiculoPrivado));
    }

    public function testVeiculosAcessiveisListaProprioSemDuplicarComCompartilhamento(): void
    {
        $donoId = $this->criarUsuario();
        $veiculoId = $this->criarVeiculo($donoId);

        // O próprio dono nunca aparece como "colaborador" do próprio veículo
        // (não existe linha em veiculo_compartilhamentos pra ele) — a lista
        // não pode duplicar por causa disso.
        $lista = veiculosAcessiveis($this->pdo, $donoId);

        $this->assertCount(1, $lista);
        $this->assertTrue($lista[0]['e_dono']);
    }
}
