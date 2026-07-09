<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testa as funções puras de src/includes/functions.php — sem dependência de
 * banco de dados (nenhuma delas recebe PDO).
 */
final class FuncoesPurasTest extends TestCase
{
    // --- h() -------------------------------------------------------------

    public function testHEscapaHtmlParaPrevenirXss(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            h('<script>alert(1)</script>')
        );
    }

    public function testHEscapaAspasDuplasESimples(): void
    {
        $this->assertSame('&quot;a&quot; &amp; &#039;b&#039;', h('"a" & \'b\''));
    }

    public function testHAceitaNullEDevolveStringVazia(): void
    {
        $this->assertSame('', h(null));
    }

    // --- formatarMoeda() ---------------------------------------------------

    public function testFormatarMoedaUsaFormatoBrasileiro(): void
    {
        $this->assertSame('R$ 1.234,56', formatarMoeda(1234.56));
    }

    public function testFormatarMoedaComZero(): void
    {
        $this->assertSame('R$ 0,00', formatarMoeda(0.0));
    }

    public function testFormatarMoedaArredondaParaDuasCasas(): void
    {
        $this->assertSame('R$ 10,00', formatarMoeda(9.999));
    }

    // --- sanitizarCelulaCsv() (CSV/Formula Injection) ----------------------

    #[DataProvider('prefixosPerigososProvider')]
    public function testSanitizarCelulaCsvNeutralizaPrefixosDeFormula(string $valor): void
    {
        $resultado = sanitizarCelulaCsv($valor);
        $this->assertStringStartsWith("'", $resultado);
        $this->assertSame("'" . $valor, $resultado);
    }

    public static function prefixosPerigososProvider(): array
    {
        return [
            'igual' => ['=cmd|"/c calc"!A1'],
            'mais' => ['+1+1'],
            'menos' => ['-1+1'],
            'arroba' => ['@SUM(1+1)'],
            'tab' => ["\tformula"],
            'carriage-return' => ["\rformula"],
        ];
    }

    public function testSanitizarCelulaCsvNaoAlteraTextoNormal(): void
    {
        $this->assertSame('Troca de óleo', sanitizarCelulaCsv('Troca de óleo'));
    }

    public function testSanitizarCelulaCsvAceitaStringVazia(): void
    {
        $this->assertSame('', sanitizarCelulaCsv(''));
    }

    // --- calcularStatusLembrete() ------------------------------------------

    public function testStatusLembretePorDataVencidoQuandoDataPassou(): void
    {
        $ontem = (new DateTime('yesterday'))->format('Y-m-d');
        $status = calcularStatusLembrete(['tipo_alvo' => 'Data', 'data_alvo' => $ontem]);
        $this->assertSame('vencido', $status['status']);
    }

    public function testStatusLembretePorDataProximoDentroDe15Dias(): void
    {
        $daqui10Dias = (new DateTime('+10 days'))->format('Y-m-d');
        $status = calcularStatusLembrete(['tipo_alvo' => 'Data', 'data_alvo' => $daqui10Dias]);
        $this->assertSame('proximo', $status['status']);
    }

    public function testStatusLembretePorDataOkQuandoFaltaMaisDe15Dias(): void
    {
        $daqui30Dias = (new DateTime('+30 days'))->format('Y-m-d');
        $status = calcularStatusLembrete(['tipo_alvo' => 'Data', 'data_alvo' => $daqui30Dias]);
        $this->assertSame('ok', $status['status']);
    }

    public function testStatusLembretePorKmVencidoQuandoJaPassouDoAlvo(): void
    {
        $status = calcularStatusLembrete(['tipo_alvo' => 'KM', 'km_alvo' => 10000, 'km_atual_veiculo' => 10500]);
        $this->assertSame('vencido', $status['status']);
    }

    public function testStatusLembretePorKmProximoDentroDe500Km(): void
    {
        $status = calcularStatusLembrete(['tipo_alvo' => 'KM', 'km_alvo' => 10000, 'km_atual_veiculo' => 9600]);
        $this->assertSame('proximo', $status['status']);
    }

    public function testStatusLembretePorKmOkQuandoFaltaMaisDe500Km(): void
    {
        $status = calcularStatusLembrete(['tipo_alvo' => 'KM', 'km_alvo' => 10000, 'km_atual_veiculo' => 5000]);
        $this->assertSame('ok', $status['status']);
    }

    public function testStatusLembretePorKmOkQuandoVeiculoAindaSemRegistro(): void
    {
        // Sem nenhum registro ainda não dá pra saber o km atual — não classifica
        // como vencido/próximo (ver docblock de calcularStatusLembrete).
        $status = calcularStatusLembrete(['tipo_alvo' => 'KM', 'km_alvo' => 10000, 'km_atual_veiculo' => null]);
        $this->assertSame('ok', $status['status']);
    }

    // --- Whitelists (usadas por validarRegistro) ----------------------------

    public function testCombustiveisPermitidosNaoAceitaValorArbitrario(): void
    {
        $this->assertContains('Etanol', COMBUSTIVEIS_PERMITIDOS);
        $this->assertNotContains('Gasosa Inventada', COMBUSTIVEIS_PERMITIDOS);
    }

    public function testCategoriasDespesaPermitidasNaoAceitaValorArbitrario(): void
    {
        $this->assertContains('IPVA', CATEGORIAS_DESPESA_PERMITIDAS);
        $this->assertNotContains('Categoria Inventada', CATEGORIAS_DESPESA_PERMITIDAS);
    }
}
