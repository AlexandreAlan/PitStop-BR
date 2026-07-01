<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$veiculosStmt = $pdo->prepare('SELECT id, nome, tipo FROM veiculos WHERE usuario_id = :usuario_id ORDER BY nome');
$veiculosStmt->execute([':usuario_id' => $usuario['id']]);
$veiculos = $veiculosStmt->fetchAll();

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

$bind = function (PDOStatement $stmt) use ($usuario, $veiculoIdFiltro, $dataInicioFiltro, $dataFimFiltro): void {
    $stmt->bindValue(':usuario_id', $usuario['id'], PDO::PARAM_INT);
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
        'SELECT r.data, v.nome AS veiculo, r.tipo_registro, r.combustivel, r.litros, r.categoria_despesa, r.valor_pago, r.descricao
         FROM registros r
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE v.usuario_id = :usuario_id' . $filtroVeiculoSql . '
         ORDER BY r.data'
    );
    $bind($stmt);
    $stmt->execute();
    $linhasExportacao = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pitstop-relatorio-' . date('Y-m-d') . '.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF"); // BOM pra abrir certinho com acentos no Excel
    fputcsv($saida, ['Data', 'Veiculo', 'Tipo', 'Combustivel/Categoria', 'Litros', 'Valor (R$)', 'Descricao'], ';');
    foreach ($linhasExportacao as $l) {
        fputcsv($saida, [
            (new DateTime($l['data']))->format('d/m/Y'),
            $l['veiculo'],
            $l['tipo_registro'],
            $l['combustivel'] ?? $l['categoria_despesa'] ?? '',
            $l['litros'] !== null ? number_format((float) $l['litros'], 2, ',', '.') : '',
            number_format((float) $l['valor_pago'], 2, ',', '.'),
            (string) $l['descricao'],
        ], ';');
    }
    fclose($saida);
    exit;
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
     WHERE v.usuario_id = :usuario_id' . $filtroVeiculoSql
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

// Gasto por mês
$stmt = $pdo->prepare(
    'SELECT DATE_FORMAT(r.data, "%Y-%m") AS mes, SUM(r.valor_pago) AS total
     FROM registros r
     INNER JOIN veiculos v ON v.id = r.veiculo_id
     WHERE v.usuario_id = :usuario_id' . $filtroVeiculoSql . '
     GROUP BY mes ORDER BY mes'
);
$bind($stmt);
$stmt->execute();
$gastoPorMes = $stmt->fetchAll();

// Km rodado por mês (diferença entre leituras consecutivas de odômetro, por veículo)
$stmt = $pdo->prepare(
    'SELECT mes, SUM(GREATEST(km_atual - km_anterior, 0)) AS km_rodado FROM (
        SELECT DATE_FORMAT(r.data, "%Y-%m") AS mes, r.km_atual,
               LAG(r.km_atual) OVER (PARTITION BY r.veiculo_id ORDER BY r.km_atual) AS km_anterior
        FROM registros r
        INNER JOIN veiculos v ON v.id = r.veiculo_id
        WHERE v.usuario_id = :usuario_id' . $filtroVeiculoSql . '
     ) t
     WHERE km_anterior IS NOT NULL
     GROUP BY mes ORDER BY mes'
);
$bind($stmt);
$stmt->execute();
$kmPorMes = $stmt->fetchAll();

// Evolução do consumo (km/l) entre abastecimentos consecutivos, por veículo
$stmt = $pdo->prepare(
    'SELECT data, km_atual, litros, km_anterior FROM (
        SELECT r.data, r.km_atual, r.litros,
               LAG(r.km_atual) OVER (PARTITION BY r.veiculo_id ORDER BY r.km_atual) AS km_anterior
        FROM registros r
        INNER JOIN veiculos v ON v.id = r.veiculo_id
        WHERE v.usuario_id = :usuario_id
          AND r.tipo_registro = "Abastecimento" AND r.litros IS NOT NULL' . $filtroVeiculoSql . '
     ) t
     WHERE km_anterior IS NOT NULL
     ORDER BY km_atual'
);
$bind($stmt);
$stmt->execute();
$consumoBruto = $stmt->fetchAll();

$consumo = [];
foreach ($consumoBruto as $c) {
    $kmRodado = (int) $c['km_atual'] - (int) $c['km_anterior'];
    $litros   = (float) $c['litros'];
    if ($kmRodado > 0 && $litros > 0) {
        $consumo[] = [
            'data'  => (new DateTime($c['data']))->format('d/m/Y'),
            'kml'   => round($kmRodado / $litros, 1),
        ];
    }
}

// Comparação com o consumo de fábrica — só faz sentido com 1 veículo
// selecionado (misturar veículos diferentes no mesmo km/l não diz nada).
$consumoFabrica = null;
$consumoMedioReal = null;
if ($veiculoIdFiltro !== null) {
    $stmt = $pdo->prepare(
        'SELECT m.consumo_cidade_kml, m.consumo_estrada_kml
         FROM veiculos v INNER JOIN modelos_veiculos m ON m.id = v.modelo_veiculo_id
         WHERE v.id = :veiculo_id AND v.usuario_id = :usuario_id'
    );
    $stmt->execute([':veiculo_id' => $veiculoIdFiltro, ':usuario_id' => $usuario['id']]);
    $consumoFabrica = $stmt->fetch() ?: null;

    if ($consumoFabrica !== null && $consumo) {
        $consumoMedioReal = round(array_sum(array_column($consumo, 'kml')) / count($consumo), 1);
    }
}

$labelsGastoMes = array_map(static fn($g) => $g['mes'], $gastoPorMes);
$valoresGastoMes = array_map(static fn($g) => (float) $g['total'], $gastoPorMes);
$labelsKmMes = array_map(static fn($k) => $k['mes'], $kmPorMes);
$valoresKmMes = array_map(static fn($k) => (int) $k['km_rodado'], $kmPorMes);
$labelsConsumo = array_map(static fn($c) => $c['data'], $consumo);
$valoresConsumo = array_map(static fn($c) => $c['kml'], $consumo);

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
    <div class="row gx-2">
        <div class="col-6">
            <label class="form-label small text-muted mb-1">De</label>
            <input type="date" name="data_inicio" class="form-control" value="<?= h((string) $dataInicioFiltro) ?>">
        </div>
        <div class="col-6">
            <label class="form-label small text-muted mb-1">Até</label>
            <input type="date" name="data_fim" class="form-control" value="<?= h((string) $dataFimFiltro) ?>">
        </div>
    </div>
    <div class="d-flex gap-2 mt-2">
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
</form>

<div class="row gx-2 px-1 mb-3">
    <div class="col-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <span class="icone-chip icone-chip-laranja mb-2" aria-hidden="true"><i class="bi bi-cash-stack"></i></span>
                <p class="text-muted small mb-1">Total Gasto</p>
                <p class="fw-bold mb-0 small stat-valor"><?= h(formatarMoeda($totalGasto)) ?></p>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <span class="icone-chip icone-chip-teal mb-2" aria-hidden="true"><i class="bi bi-calendar3"></i></span>
                <p class="text-muted small mb-1">Gasto Médio/Dia</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $gastoMedioDia !== null ? h(formatarMoeda($gastoMedioDia)) : '—' ?></p>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <span class="icone-chip icone-chip-laranja mb-2" aria-hidden="true"><i class="bi bi-fuel-pump"></i></span>
                <p class="text-muted small mb-1">Preço Médio/L</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $precoMedioLitro !== null ? h(formatarMoeda($precoMedioLitro)) : '—' ?></p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6 px-1 mb-4">
        <h6 class="text-muted mb-2">Gasto por Mês</h6>
        <?php if (!$gastoPorMes): ?>
            <div class="estado-vazio-mini"><i class="bi bi-bar-chart" aria-hidden="true"></i>Sem dados suficientes ainda.</div>
        <?php else: ?>
            <div class="card shadow-sm border-0"><div class="card-body"><canvas id="graficoGastoMes" height="180"></canvas></div></div>
        <?php endif; ?>
    </div>

    <div class="col-lg-6 px-1 mb-4">
        <h6 class="text-muted mb-2">Km Rodado por Mês</h6>
        <?php if (!$kmPorMes): ?>
            <div class="estado-vazio-mini"><i class="bi bi-signpost-split" aria-hidden="true"></i>Sem dados suficientes ainda.</div>
        <?php else: ?>
            <div class="card shadow-sm border-0"><div class="card-body"><canvas id="graficoKmMes" height="180"></canvas></div></div>
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

<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Evolução do Consumo (km/l)</h6>
    <?php if (!$consumo): ?>
        <div class="estado-vazio-mini"><i class="bi bi-graph-up" aria-hidden="true"></i>Precisa de pelo menos 2 abastecimentos do mesmo veículo.</div>
    <?php else: ?>
        <div class="card shadow-sm border-0"><div class="card-body"><canvas id="graficoConsumo" height="180"></canvas></div></div>
    <?php endif; ?>
</div>

<script src="assets/js/index.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script type="application/json" id="dados-relatorios"><?= json_encode([
    'gastoMes' => ['labels' => $labelsGastoMes, 'valores' => $valoresGastoMes],
    'kmMes'    => ['labels' => $labelsKmMes, 'valores' => $valoresKmMes],
    'consumo'  => ['labels' => $labelsConsumo, 'valores' => $valoresConsumo],
], $jsonFlags) ?></script>
<script src="assets/js/relatorios.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
