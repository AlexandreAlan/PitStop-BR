<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$veiculos = veiculosAcessiveis($pdo, $usuario['id']);

$veiculoIdFiltro = filter_input(INPUT_GET, 'veiculo_id', FILTER_VALIDATE_INT) ?: null;

$dataInicioFiltro = null;
$dataInicioBruta = (string) ($_GET['data_inicio'] ?? '');
if ($dataInicioBruta !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $dataInicioBruta);
    $dataInicioFiltro = ($d && $d->format('Y-m-d') === $dataInicioBruta) ? $dataInicioBruta : null;
}

$dataFimFiltro = null;
$dataFimBruta = (string) ($_GET['data_fim'] ?? '');
if ($dataFimBruta !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $dataFimBruta);
    $dataFimFiltro = ($d && $d->format('Y-m-d') === $dataFimBruta) ? $dataFimBruta : null;
}

$filtroVeiculoSql = ($veiculoIdFiltro !== null ? ' AND r.veiculo_id = :veiculo_id' : '')
    . ($dataInicioFiltro !== null ? ' AND r.data >= :data_inicio' : '')
    . ($dataFimFiltro !== null ? ' AND r.data <= :data_fim' : '');

// Agrupamento dos gráficos de Gasto/Km: dia, semana (segunda-feira como
// início) ou mês (padrão, comportamento de antes). A chave de agrupamento é
// sempre uma DATE de verdade (não uma string "%Y-%m"), pra ordenar certo nos
// três casos com a mesma lógica — o rótulo bonito é formatado à parte, em PHP.
$agrupamentosPermitidos = ['dia', 'semana', 'mes'];
$agrupamento = in_array($_GET['agrupamento'] ?? '', $agrupamentosPermitidos, true) ? $_GET['agrupamento'] : 'mes';
$grupoDataSql = match ($agrupamento) {
    'dia'    => 'DATE(r.data)',
    'semana' => 'DATE_SUB(r.data, INTERVAL WEEKDAY(r.data) DAY)',
    default  => 'DATE_FORMAT(r.data, "%Y-%m-01")',
};

$mesesAbrev = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
function formatarRotuloPeriodo(string $dataStr, string $agrupamento, array $mesesAbrev): string
{
    $d = new DateTime($dataStr);
    return match ($agrupamento) {
        'dia'    => $d->format('d/m'),
        'semana' => $d->format('d/m') . '–' . (clone $d)->modify('+6 days')->format('d/m'),
        default  => $mesesAbrev[(int) $d->format('n') - 1] . '/' . $d->format('y'),
    };
}

$bind = function (PDOStatement $stmt) use ($usuario, $veiculoIdFiltro, $dataInicioFiltro, $dataFimFiltro): void {
    bindAcessoVeiculo($stmt, $usuario['id']);
    if ($veiculoIdFiltro !== null) {
        $stmt->bindValue(':veiculo_id', $veiculoIdFiltro, PDO::PARAM_INT);
    }
    if ($dataInicioFiltro !== null) {
        $stmt->bindValue(':data_inicio', $dataInicioFiltro);
    }
    if ($dataFimFiltro !== null) {
        $stmt->bindValue(':data_fim', $dataFimFiltro);
    }
};

if (($_GET['formato'] ?? '') === 'csv') {
    $stmt = $pdo->prepare(
        'SELECT r.data, v.nome AS veiculo, r.tipo_registro, r.combustivel, r.litros, r.tanque_cheio, r.km_atual, r.categoria_despesa, r.valor_pago, r.descricao
         FROM registros r
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE ' . condicaoAcessoVeiculo('v') . $filtroVeiculoSql . '
         ORDER BY r.data'
    );
    $bind($stmt);
    $stmt->execute();
    $linhasExportacao = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pitstop-relatorio-' . date('Y-m-d') . '.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF"); // BOM pra abrir certinho com acentos no Excel
    // Formato usado também na importação (importar.php) — ver
    // importarLinhaCsv() em includes/functions.php. Km e TanqueCheio entram
    // aqui pra tornar a exportação de fato reimportável: sem o km (campo
    // obrigatório em registros), um CSV exportado não dava pra voltar pro
    // sistema de jeito nenhum.
    fputcsv($saida, ['Data', 'Veiculo', 'Tipo', 'Combustivel/Categoria', 'Litros', 'Km', 'TanqueCheio', 'Valor (R$)', 'Descricao'], ';');
    foreach ($linhasExportacao as $l) {
        fputcsv($saida, [
            (new DateTime($l['data']))->format('d/m/Y'),
            sanitizarCelulaCsv($l['veiculo']),
            $l['tipo_registro'],
            $l['combustivel'] ?? $l['categoria_despesa'] ?? '',
            $l['litros'] !== null ? number_format((float) $l['litros'], 2, ',', '.') : '',
            (string) (int) $l['km_atual'],
            $l['tipo_registro'] === 'Abastecimento' ? ((int) $l['tanque_cheio'] === 1 ? 'cheio' : 'parcial') : '',
            number_format((float) $l['valor_pago'], 2, ',', '.'),
            sanitizarCelulaCsv((string) $l['descricao']),
        ], ';');
    }
    fclose($saida);
    exit;
}

// Comparação entre veículos: só faz sentido com 2+ veículos cadastrados.
// Reusa o mesmo recorte de período dos filtros acima (não o de veículo, que
// aqui é irrelevante — a comparação sempre olha todos os veículos).
$comparacaoVeiculos = [];
if (count($veiculos) > 1) {
    foreach ($veiculos as $v) {
        $comparacaoVeiculos[] = [
            'veiculo' => $v,
            'stats'   => calcularEstatisticasVeiculo($pdo, $usuario['id'], (int) $v['id'], $dataInicioFiltro, $dataFimFiltro),
        ];
    }
}

// Preço médio por litro pago em cada posto, no mesmo recorte de
// veículo/período do restante da página (ver precoMedioPorPosto()).
$precoPorPosto = precoMedioPorPosto($pdo, $usuario['id'], $veiculoIdFiltro, $dataInicioFiltro, $dataFimFiltro);
$menorPrecoPosto = $precoPorPosto ? min(array_column($precoPorPosto, 'preco_medio_litro')) : null;

// Destaque do melhor valor por linha — só faz sentido pra consumo (maior é
// melhor) e custo/km (menor é melhor). Km rodado e gasto não têm "melhor":
// rodar mais ou gastar mais não é bom nem ruim, só reflete o uso.
$melhorConsumoIdx = null;
$melhorCustoKmIdx = null;
foreach ($comparacaoVeiculos as $i => $c) {
    if ($c['stats']['consumo_medio'] !== null
        && ($melhorConsumoIdx === null || $c['stats']['consumo_medio'] > $comparacaoVeiculos[$melhorConsumoIdx]['stats']['consumo_medio'])) {
        $melhorConsumoIdx = $i;
    }
    if ($c['stats']['custo_km'] !== null
        && ($melhorCustoKmIdx === null || $c['stats']['custo_km'] < $comparacaoVeiculos[$melhorCustoKmIdx]['stats']['custo_km'])) {
        $melhorCustoKmIdx = $i;
    }
}
// Empate entre todos (ex.: só 1 tem dado) não é destaque de verdade.
if ($melhorConsumoIdx !== null && count(array_unique(array_column(array_column($comparacaoVeiculos, 'stats'), 'consumo_medio'))) <= 1) {
    $melhorConsumoIdx = null;
}
if ($melhorCustoKmIdx !== null && count(array_unique(array_column(array_column($comparacaoVeiculos, 'stats'), 'custo_km'))) <= 1) {
    $melhorCustoKmIdx = null;
}

// Resumo: total gasto, gasto médio por dia, preço médio por litro
$stmt = $pdo->prepare(
    'SELECT COALESCE(SUM(r.valor_pago), 0) AS total_gasto,
            MIN(r.data) AS primeira_data,
            MAX(r.data) AS ultima_data,
            COALESCE(SUM(CASE WHEN r.tipo_registro = "Abastecimento" THEN r.litros ELSE 0 END), 0) AS total_litros,
            COALESCE(SUM(CASE WHEN r.tipo_registro = "Abastecimento" THEN r.valor_pago ELSE 0 END), 0) AS total_gasto_combustivel
     FROM registros r
     INNER JOIN veiculos v ON v.id = r.veiculo_id
     WHERE ' . condicaoAcessoVeiculo('v') . $filtroVeiculoSql
);
$bind($stmt);
$stmt->execute();
$resumo = $stmt->fetch();

$totalGasto = (float) $resumo['total_gasto'];
$precoMedioLitro = (float) $resumo['total_litros'] > 0
    ? (float) $resumo['total_gasto_combustivel'] / (float) $resumo['total_litros']
    : null;

$gastoMedioDia = null;
if ($resumo['primeira_data'] !== null) {
    $dias = (new DateTime($resumo['primeira_data']))->diff(new DateTime($resumo['ultima_data']))->days + 1;
    $gastoMedioDia = $totalGasto / max($dias, 1);
}

// Onde vai o dinheiro: agrupa o gasto por categoria (combustível, manutenção
// e cada categoria de despesa), pra mostrar a distribuição num gráfico rosca.
$stmt = $pdo->prepare(
    "SELECT categoria, SUM(valor_pago) AS total FROM (
        SELECT CASE r.tipo_registro
                   WHEN 'Abastecimento' THEN 'Combustível'
                   WHEN 'Manutencao' THEN 'Manutenção'
                   ELSE COALESCE(r.categoria_despesa, 'Outro')
               END AS categoria,
               r.valor_pago
        FROM registros r
        INNER JOIN veiculos v ON v.id = r.veiculo_id
        WHERE " . condicaoAcessoVeiculo('v') . $filtroVeiculoSql . '
     ) t
     GROUP BY categoria ORDER BY total DESC'
);
$bind($stmt);
$stmt->execute();
$gastoPorCategoria = $stmt->fetchAll();

// Gasto por período (dia/semana/mês, ver $grupoDataSql acima)
$stmt = $pdo->prepare(
    'SELECT ' . $grupoDataSql . ' AS periodo, SUM(r.valor_pago) AS total
     FROM registros r
     INNER JOIN veiculos v ON v.id = r.veiculo_id
     WHERE ' . condicaoAcessoVeiculo('v') . $filtroVeiculoSql . '
     GROUP BY periodo ORDER BY periodo'
);
$bind($stmt);
$stmt->execute();
$gastoPorPeriodo = $stmt->fetchAll();

// Km rodado por período (diferença entre leituras consecutivas de odômetro,
// por veículo — atribuído ao período da leitura mais recente do par)
$stmt = $pdo->prepare(
    'SELECT periodo, SUM(GREATEST(km_atual - km_anterior, 0)) AS km_rodado FROM (
        SELECT ' . $grupoDataSql . ' AS periodo, r.km_atual,
               LAG(r.km_atual) OVER (PARTITION BY r.veiculo_id ORDER BY r.km_atual) AS km_anterior
        FROM registros r
        INNER JOIN veiculos v ON v.id = r.veiculo_id
        WHERE ' . condicaoAcessoVeiculo('v') . $filtroVeiculoSql . '
     ) t
     WHERE km_anterior IS NOT NULL
     GROUP BY periodo ORDER BY periodo'
);
$bind($stmt);
$stmt->execute();
$kmPorPeriodo = $stmt->fetchAll();

// Histórico de abastecimentos: lista crua (data, km, litros, tanque cheio),
// independente de fechar trecho ou não — é a resposta direta pra "que dia
// eu abasteci" e "quando abasteci de novo", sem passar pela conta de km/l
// (que só existe quando dois abastecimentos de tanque cheio se fecham).
$stmt = $pdo->prepare(
    'SELECT r.data, r.km_atual, r.litros, r.tanque_cheio, r.valor_pago, r.combustivel, v.nome AS veiculo_nome
     FROM registros r
     INNER JOIN veiculos v ON v.id = r.veiculo_id
     WHERE ' . condicaoAcessoVeiculo('v') . ' AND r.tipo_registro = "Abastecimento"' . $filtroVeiculoSql . '
     ORDER BY r.data DESC, r.km_atual DESC
     LIMIT 50'
);
$bind($stmt);
$stmt->execute();
$historicoAbastecimentos = $stmt->fetchAll();

// Evolução do consumo (km/l): calcularTrechosConsumo() respeita
// tanque_cheio (ver includes/functions.php) — um abastecimento parcial não
// fecha trecho sozinho, seus litros ficam acumulados até o próximo tanque
// cheio. Sem filtro de veículo, junta os trechos de todos e ordena por km
// (mistura consumos de veículos diferentes na mesma linha, igual já era).
$veiculosParaConsumo = $veiculoIdFiltro !== null ? [$veiculoIdFiltro] : array_column($veiculos, 'id');

$consumo = [];
foreach ($veiculosParaConsumo as $vid) {
    foreach (calcularTrechosConsumo($pdo, $usuario['id'], (int) $vid, $dataInicioFiltro, $dataFimFiltro) as $trecho) {
        $consumo[] = [
            'km_atual' => $trecho['km_atual'],
            'data'     => (new DateTime($trecho['data']))->format('d/m/Y'),
            'kml'      => round($trecho['consumo'], 1),
        ];
    }
}
usort($consumo, static fn(array $a, array $b): int => $a['km_atual'] <=> $b['km_atual']);

// Consumo médio (card do topo) — só faz sentido quando o veículo é
// inequívoco (filtro ativo, ou usuário com um único veículo), mesmo
// critério já usado pra autonomia estimada no dashboard: misturar km/l de
// veículos diferentes no mesmo número não diz nada de útil.
$veiculoConsumoInequivoco = $veiculoIdFiltro ?? (count($veiculos) === 1 ? (int) $veiculos[0]['id'] : null);
$consumoMedioCard = ($veiculoConsumoInequivoco !== null && $consumo)
    ? round(array_sum(array_column($consumo, 'kml')) / count($consumo), 1)
    : null;

// Comparação com o consumo de fábrica — só faz sentido com 1 veículo
// selecionado (misturar veículos diferentes no mesmo km/l não diz nada).
$consumoFabrica = null;
$consumoMedioReal = null;
if ($veiculoIdFiltro !== null) {
    $stmt = $pdo->prepare(
        'SELECT m.consumo_cidade_kml, m.consumo_estrada_kml
         FROM veiculos v INNER JOIN modelos_veiculos m ON m.id = v.modelo_veiculo_id
         WHERE v.id = :veiculo_id AND ' . condicaoAcessoVeiculo('v')
    );
    $stmt->bindValue(':veiculo_id', $veiculoIdFiltro, PDO::PARAM_INT);
    bindAcessoVeiculo($stmt, $usuario['id']);
    $stmt->execute();
    $consumoFabrica = $stmt->fetch() ?: null;

    if ($consumoFabrica !== null && $consumo) {
        $consumoMedioReal = round(array_sum(array_column($consumo, 'kml')) / count($consumo), 1);
    }
}

// Benchmark anônimo: só com 1 veículo selecionado (mesmo critério da
// comparação com fábrica acima) — ver calcularBenchmarkConsumo().
$benchmarkConsumo = $veiculoIdFiltro !== null ? calcularBenchmarkConsumo($pdo, $usuario['id'], $veiculoIdFiltro) : null;

$labelsGastoMes = array_map(static fn($g) => formatarRotuloPeriodo($g['periodo'], $agrupamento, $mesesAbrev), $gastoPorPeriodo);
$valoresGastoMes = array_map(static fn($g) => (float) $g['total'], $gastoPorPeriodo);
$labelsKmMes = array_map(static fn($k) => formatarRotuloPeriodo($k['periodo'], $agrupamento, $mesesAbrev), $kmPorPeriodo);
$valoresKmMes = array_map(static fn($k) => (int) $k['km_rodado'], $kmPorPeriodo);
$labelsConsumo = array_map(static fn($c) => $c['data'], $consumo);
$valoresConsumo = array_map(static fn($c) => $c['kml'], $consumo);
$labelsCategorias = array_map(static fn($c) => $c['categoria'], $gastoPorCategoria);
$valoresCategorias = array_map(static fn($c) => (float) $c['total'], $gastoPorCategoria);

// Custo por km: total gasto (no período/filtro) dividido pelo km total rodado
// no mesmo recorte (soma do km rodado por período, já calculado acima).
$totalKmRodado = (int) array_sum($valoresKmMes);
$custoPorKm = $totalKmRodado > 0 ? $totalGasto / $totalKmRodado : null;

// Comparação com o período anterior de mesmo tamanho (ex.: filtro "este mês"
// compara com o mês passado inteiro) — só calculável com os dois limites do
// filtro definidos, senão não dá pra saber o tamanho do período pra replicar
// pra trás.
$variacaoGastoPercentual = null;
if ($dataInicioFiltro !== null && $dataFimFiltro !== null) {
    $inicio = new DateTime($dataInicioFiltro);
    $fim    = new DateTime($dataFimFiltro);
    $duracaoDias = $inicio->diff($fim)->days + 1;
    $inicioAnterior = (clone $inicio)->modify("-{$duracaoDias} days")->format('Y-m-d');
    $fimAnterior    = (clone $inicio)->modify('-1 day')->format('Y-m-d');

    $stmtAnterior = $pdo->prepare(
        'SELECT COALESCE(SUM(r.valor_pago), 0) FROM registros r
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE ' . condicaoAcessoVeiculo('v') . ' AND r.data >= :data_inicio AND r.data <= :data_fim'
        . ($veiculoIdFiltro !== null ? ' AND r.veiculo_id = :veiculo_id' : '')
    );
    bindAcessoVeiculo($stmtAnterior, $usuario['id']);
    $stmtAnterior->bindValue(':data_inicio', $inicioAnterior);
    $stmtAnterior->bindValue(':data_fim', $fimAnterior);
    if ($veiculoIdFiltro !== null) {
        $stmtAnterior->bindValue(':veiculo_id', $veiculoIdFiltro, PDO::PARAM_INT);
    }
    $stmtAnterior->execute();
    $gastoAnterior = (float) $stmtAnterior->fetchColumn();

    if ($gastoAnterior > 0) {
        $variacaoGastoPercentual = (int) round((($totalGasto - $gastoAnterior) / $gastoAnterior) * 100);
    }
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

$tituloPagina = 'Relatórios — PitStop BR';
$mostrarVoltar = true;
require __DIR__ . '/includes/header.php';
?>

<form method="get" class="px-1 mb-3" id="formFiltroVeiculo">
    <?php if (count($veiculos) > 1): ?>
    <div class="mb-2">
        <select name="veiculo_id" class="form-select" id="selectVeiculoFiltro">
            <option value="">Todos os veículos</option>
            <?php foreach ($veiculos as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= $veiculoIdFiltro === (int) $v['id'] ? 'selected' : '' ?>>
                <?= h($v['nome']) ?> (<?= h($v['tipo']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="d-flex gap-2 mb-2 atalhos-periodo flex-wrap">
        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" data-periodo="7dias">Últimos 7 dias</button>
        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" data-periodo="mes">Este mês</button>
        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" data-periodo="mespassado">Mês passado</button>
        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" data-periodo="30dias">Últimos 30 dias</button>
        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" data-periodo="semanapassada">Semana passada</button>
        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" data-periodo="ano">Este ano</button>
    </div>
    <div class="row gx-2">
        <div class="col-6">
            <label class="form-label small text-muted mb-1">De</label>
            <input type="date" name="data_inicio" id="campoDataInicio" class="form-control" value="<?= h((string) $dataInicioFiltro) ?>">
        </div>
        <div class="col-6">
            <label class="form-label small text-muted mb-1">Até</label>
            <input type="date" name="data_fim" id="campoDataFim" class="form-control" value="<?= h((string) $dataFimFiltro) ?>">
        </div>
    </div>
    <div class="mt-2">
        <label class="form-label small text-muted mb-1">Agrupar gráficos por</label>
        <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="agrupamento" id="agrupDia" value="dia" <?= $agrupamento === 'dia' ? 'checked' : '' ?> onchange="document.getElementById('formFiltroVeiculo').requestSubmit()">
            <label class="btn btn-outline-secondary btn-sm" for="agrupDia">Dia</label>
            <input type="radio" class="btn-check" name="agrupamento" id="agrupSemana" value="semana" <?= $agrupamento === 'semana' ? 'checked' : '' ?> onchange="document.getElementById('formFiltroVeiculo').requestSubmit()">
            <label class="btn btn-outline-secondary btn-sm" for="agrupSemana">Semana</label>
            <input type="radio" class="btn-check" name="agrupamento" id="agrupMes" value="mes" <?= $agrupamento === 'mes' ? 'checked' : '' ?> onchange="document.getElementById('formFiltroVeiculo').requestSubmit()">
            <label class="btn btn-outline-secondary btn-sm" for="agrupMes">Mês</label>
        </div>
    </div>
    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary flex-fill">
            <i class="bi bi-funnel me-1"></i>Filtrar
        </button>
        <a class="btn btn-outline-secondary flex-fill" href="relatorios.php?formato=csv&<?= h(http_build_query(['veiculo_id' => $veiculoIdFiltro, 'data_inicio' => $dataInicioFiltro, 'data_fim' => $dataFimFiltro])) ?>">
            <i class="bi bi-download me-1"></i>CSV
        </a>
        <button type="button" class="btn btn-outline-secondary flex-fill" id="botaoExportarPdf">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </button>
    </div>
    <a href="importar.php" class="btn btn-outline-secondary btn-sm w-100 mt-2">
        <i class="bi bi-upload me-1"></i>Importar Histórico (CSV)
    </a>
</form>

<div class="row gx-2 px-1 mb-3">
    <div class="col-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <span class="icone-chip icone-chip-laranja mb-2" aria-hidden="true"><i class="bi bi-cash-stack"></i></span>
                <p class="text-muted small mb-1">Total Gasto</p>
                <p class="fw-bold mb-0 small stat-valor"><?= h(formatarMoeda($totalGasto)) ?></p>
                <?php if ($variacaoGastoPercentual !== null): ?>
                <p class="small mb-0 mt-1 <?= $variacaoGastoPercentual > 0 ? 'text-danger' : 'text-success' ?>">
                    <i class="bi bi-arrow-<?= $variacaoGastoPercentual > 0 ? 'up' : 'down' ?> me-1"></i><?= abs($variacaoGastoPercentual) ?>% vs período anterior
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <span class="icone-chip icone-chip-teal mb-2" aria-hidden="true"><i class="bi bi-speedometer2"></i></span>
                <p class="text-muted small mb-1">Km Rodado</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $totalKmRodado > 0 ? h(number_format($totalKmRodado, 0, ',', '.')) . ' km' : '—' ?></p>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <span class="icone-chip icone-chip-teal mb-2" aria-hidden="true"><i class="bi bi-lightning-charge"></i></span>
                <p class="text-muted small mb-1">Consumo Médio</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $consumoMedioCard !== null ? h(number_format($consumoMedioCard, 1, ',', '.')) . ' km/l' : '—' ?></p>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <span class="icone-chip icone-chip-teal mb-2" aria-hidden="true"><i class="bi bi-signpost-split"></i></span>
                <p class="text-muted small mb-1">Custo por Km</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $custoPorKm !== null ? h(formatarMoeda($custoPorKm)) . '/km' : '—' ?></p>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <span class="icone-chip icone-chip-teal mb-2" aria-hidden="true"><i class="bi bi-calendar3"></i></span>
                <p class="text-muted small mb-1">Gasto Médio/Dia</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $gastoMedioDia !== null ? h(formatarMoeda($gastoMedioDia)) : '—' ?></p>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <span class="icone-chip icone-chip-laranja mb-2" aria-hidden="true"><i class="bi bi-fuel-pump"></i></span>
                <p class="text-muted small mb-1">Preço Médio/L</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $precoMedioLitro !== null ? h(formatarMoeda($precoMedioLitro)) : '—' ?></p>
            </div>
        </div>
    </div>
</div>

<?php $rotuloAgrupamento = ['dia' => 'Dia', 'semana' => 'Semana', 'mes' => 'Mês'][$agrupamento]; ?>
<div class="row">
    <div class="col-lg-6 px-1 mb-4">
        <h6 class="text-muted mb-2">Gasto por <?= h($rotuloAgrupamento) ?></h6>
        <?php if (!$gastoPorPeriodo): ?>
            <div class="estado-vazio-mini"><i class="bi bi-bar-chart" aria-hidden="true"></i>Sem dados suficientes ainda.</div>
        <?php else: ?>
            <div class="card shadow-sm border-0"><div class="card-body"><canvas id="graficoGastoMes" height="180"></canvas></div></div>
        <?php endif; ?>
    </div>

    <div class="col-lg-6 px-1 mb-4">
        <h6 class="text-muted mb-2">Km Rodado por <?= h($rotuloAgrupamento) ?></h6>
        <?php if (!$kmPorPeriodo): ?>
            <div class="estado-vazio-mini"><i class="bi bi-signpost-split" aria-hidden="true"></i>Sem dados suficientes ainda.</div>
        <?php else: ?>
            <div class="card shadow-sm border-0"><div class="card-body"><canvas id="graficoKmMes" height="180"></canvas></div></div>
        <?php endif; ?>
    </div>

    <div class="col-lg-6 px-1 mb-4">
        <h6 class="text-muted mb-2">Para Onde Vai o Dinheiro</h6>
        <?php if (!$gastoPorCategoria): ?>
            <div class="estado-vazio-mini"><i class="bi bi-pie-chart" aria-hidden="true"></i>Sem dados suficientes ainda.</div>
        <?php else: ?>
            <div class="card shadow-sm border-0"><div class="card-body"><canvas id="graficoCategorias" height="180"></canvas></div></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($consumoFabrica !== null && $consumoMedioReal !== null): ?>
<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Seu Consumo vs. Fábrica</h6>
    <div class="card shadow-sm border-0">
        <div class="card-body py-3 px-3">
            <div class="row text-center gx-2">
                <div class="col-4">
                    <p class="text-muted small mb-1">Seu consumo</p>
                    <p class="fw-bold mb-0 stat-valor"><?= h(number_format($consumoMedioReal, 1, ',', '.')) ?> km/l</p>
                </div>
                <?php if ($consumoFabrica['consumo_cidade_kml']): ?>
                <div class="col-4">
                    <p class="text-muted small mb-1">Fábrica (cidade)</p>
                    <p class="fw-bold mb-0 stat-valor"><?= h(number_format((float) $consumoFabrica['consumo_cidade_kml'], 1, ',', '.')) ?> km/l</p>
                </div>
                <?php endif; ?>
                <?php if ($consumoFabrica['consumo_estrada_kml']): ?>
                <div class="col-4">
                    <p class="text-muted small mb-1">Fábrica (estrada)</p>
                    <p class="fw-bold mb-0 stat-valor"><?= h(number_format((float) $consumoFabrica['consumo_estrada_kml'], 1, ',', '.')) ?> km/l</p>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($consumoFabrica['consumo_cidade_kml']):
                $diferenca = round((($consumoMedioReal / (float) $consumoFabrica['consumo_cidade_kml']) - 1) * 100);
            ?>
            <p class="text-muted small text-center mb-0 mt-2">
                <?= $diferenca >= 0
                    ? 'Você está rendendo ' . abs($diferenca) . '% a mais que o consumo de cidade informado pelo fabricante.'
                    : 'Você está rendendo ' . abs($diferenca) . '% a menos que o consumo de cidade informado pelo fabricante.' ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($benchmarkConsumo !== null): ?>
<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Como Você Está vs. a Média</h6>
    <div class="card shadow-sm border-0">
        <div class="card-body py-3 px-3">
            <div class="row text-center gx-2">
                <div class="col-6">
                    <p class="text-muted small mb-1">Seu consumo</p>
                    <p class="fw-bold mb-0 stat-valor"><?= h(number_format($benchmarkConsumo['seu_consumo'], 1, ',', '.')) ?> km/l</p>
                </div>
                <div class="col-6">
                    <p class="text-muted small mb-1">Média de outros veículos parecidos</p>
                    <p class="fw-bold mb-0 stat-valor"><?= h(number_format($benchmarkConsumo['media_outros'], 1, ',', '.')) ?> km/l</p>
                </div>
            </div>
            <p class="text-muted small text-center mb-0 mt-2">
                <?= $benchmarkConsumo['diferenca_percentual'] >= 0
                    ? 'Você está rendendo ' . abs($benchmarkConsumo['diferenca_percentual']) . '% a mais'
                    : 'Você está rendendo ' . abs($benchmarkConsumo['diferenca_percentual']) . '% a menos' ?>
                que a média de <?= (int) $benchmarkConsumo['amostra'] ?> outros
                <?= h(mb_strtolower((string) $benchmarkConsumo['tipo'])) ?>s a <?= h($benchmarkConsumo['combustivel']) ?>
                — melhor que <?= (int) $benchmarkConsumo['percentil'] ?>% deles.
            </p>
            <p class="text-muted small text-center mb-0 mt-2 fst-italic">
                Comparação sempre anônima e agregada — nunca mostra o consumo de nenhum outro veículo/conta individualmente.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($comparacaoVeiculos): ?>
<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Comparação entre Veículos</h6>
    <div class="card shadow-sm border-0">
        <div class="card-body p-0" style="overflow-x: auto;">
            <table class="table table-sm mb-0 align-middle text-center">
                <thead>
                    <tr>
                        <th class="text-start ps-3">&nbsp;</th>
                        <?php foreach ($comparacaoVeiculos as $c): ?>
                        <th class="small"><?= h($c['veiculo']['nome']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-start ps-3 small text-muted">Consumo médio</td>
                        <?php foreach ($comparacaoVeiculos as $i => $c): ?>
                        <td class="small fw-semibold <?= $i === $melhorConsumoIdx ? 'celula-vencedora' : '' ?>">
                            <?= $c['stats']['consumo_medio'] !== null ? h(number_format($c['stats']['consumo_medio'], 1, ',', '.')) . ' km/l' : '—' ?>
                            <?php if ($i === $melhorConsumoIdx): ?><i class="bi bi-trophy-fill ms-1" title="Melhor consumo"></i><?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="text-start ps-3 small text-muted">Custo por km</td>
                        <?php foreach ($comparacaoVeiculos as $i => $c): ?>
                        <td class="small fw-semibold <?= $i === $melhorCustoKmIdx ? 'celula-vencedora' : '' ?>">
                            <?= $c['stats']['custo_km'] !== null ? h(formatarMoeda($c['stats']['custo_km'])) : '—' ?>
                            <?php if ($i === $melhorCustoKmIdx): ?><i class="bi bi-trophy-fill ms-1" title="Menor custo"></i><?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="text-start ps-3 small text-muted">Km rodado</td>
                        <?php foreach ($comparacaoVeiculos as $c): ?>
                        <td class="small fw-semibold"><?= $c['stats']['km_rodado'] > 0 ? h(number_format($c['stats']['km_rodado'], 0, ',', '.')) . ' km' : '—' ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="text-start ps-3 small text-muted">Gasto no período</td>
                        <?php foreach ($comparacaoVeiculos as $c): ?>
                        <td class="small fw-semibold"><?= h(formatarMoeda($c['stats']['gasto'])) ?></td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($precoPorPosto): ?>
<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Preço Médio por Posto</h6>
    <div class="card shadow-sm border-0">
        <div class="card-body p-0" style="overflow-x: auto;">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="small ps-3">Posto</th>
                        <th class="small text-end">Abastecimentos</th>
                        <th class="small text-end">Preço médio/L</th>
                        <th class="small text-end pe-3">Último</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($precoPorPosto as $p): ?>
                    <tr>
                        <td class="small ps-3">
                            <?php if ($p['favorito']): ?><i class="bi bi-star-fill text-warning me-1" title="Favorito"></i><?php endif; ?>
                            <?= h($p['nome']) ?>
                        </td>
                        <td class="small text-end"><?= (int) $p['total_abastecimentos'] ?></td>
                        <td class="small text-end fw-semibold <?= (float) $p['preco_medio_litro'] === $menorPrecoPosto ? 'celula-vencedora' : '' ?>">
                            <?= h(formatarMoeda((float) $p['preco_medio_litro'])) ?>
                            <?php if ((float) $p['preco_medio_litro'] === $menorPrecoPosto): ?><i class="bi bi-trophy-fill ms-1" title="Menor preço médio"></i><?php endif; ?>
                        </td>
                        <td class="small text-end pe-3"><?= h((new DateTime($p['ultimo_abastecimento']))->format('d/m/Y')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white border-0 py-2">
            <p class="small text-muted mb-0">Considera só abastecimentos com posto informado. <a href="postos.php">Gerenciar postos</a></p>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Histórico de Abastecimentos</h6>
    <?php if (!$historicoAbastecimentos): ?>
        <div class="estado-vazio-mini"><i class="bi bi-fuel-pump" aria-hidden="true"></i>Nenhum abastecimento registrado ainda.</div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-body p-0" style="overflow-x: auto;">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="small ps-3">Data</th>
                            <?php if (count($veiculos) > 1 && $veiculoIdFiltro === null): ?><th class="small">Veículo</th><?php endif; ?>
                            <th class="small text-end">Km</th>
                            <th class="small text-end">Litros</th>
                            <th class="small text-center">Tanque</th>
                            <th class="small text-end pe-3">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historicoAbastecimentos as $ab): ?>
                        <tr>
                            <td class="small ps-3"><?= h((new DateTime($ab['data']))->format('d/m/Y')) ?></td>
                            <?php if (count($veiculos) > 1 && $veiculoIdFiltro === null): ?><td class="small text-muted"><?= h($ab['veiculo_nome']) ?></td><?php endif; ?>
                            <td class="small text-end"><?= h(number_format((float) $ab['km_atual'], 0, ',', '.')) ?></td>
                            <td class="small text-end"><?= h(number_format((float) $ab['litros'], 2, ',', '.')) ?> L</td>
                            <td class="text-center">
                                <?php if ((int) $ab['tanque_cheio'] === 1): ?>
                                <span class="badge rounded-pill bg-success" title="Encheu o tanque"><i class="bi bi-check-lg"></i> cheio</span>
                                <?php else: ?>
                                <span class="badge rounded-pill bg-warning text-dark" title="Complemento parcial">parcial</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-end pe-3"><?= h(formatarMoeda((float) $ab['valor_pago'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($historicoAbastecimentos) === 50): ?>
            <div class="card-footer bg-white border-0 text-center py-2">
                <p class="small text-muted mb-0">Mostrando os 50 mais recentes — filtre por período pra ver mais.</p>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Evolução do Consumo (km/l)</h6>
    <?php if (!$consumo): ?>
        <div class="estado-vazio-mini"><i class="bi bi-graph-up" aria-hidden="true"></i>Precisa de pelo menos 2 abastecimentos do mesmo veículo.</div>
    <?php else: ?>
        <div class="card shadow-sm border-0"><div class="card-body"><canvas id="graficoConsumo" height="180"></canvas></div></div>
    <?php endif; ?>
</div>

<script src="assets/js/index.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt" crossorigin="anonymous"></script>
<script type="application/json" id="dados-relatorios"><?= json_encode([
    'gastoMes'    => ['labels' => $labelsGastoMes, 'valores' => $valoresGastoMes],
    'kmMes'       => ['labels' => $labelsKmMes, 'valores' => $valoresKmMes],
    'consumo'     => [
        'labels' => $labelsConsumo,
        'valores' => $valoresConsumo,
        'fabricaCidade' => isset($consumoFabrica['consumo_cidade_kml']) ? (float) $consumoFabrica['consumo_cidade_kml'] : null,
        'fabricaEstrada' => isset($consumoFabrica['consumo_estrada_kml']) ? (float) $consumoFabrica['consumo_estrada_kml'] : null,
    ],
    'categorias'  => ['labels' => $labelsCategorias, 'valores' => $valoresCategorias],
], $jsonFlags) ?></script>
<script src="assets/js/relatorios.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
