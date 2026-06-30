<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$veiculosStmt = $pdo->prepare('SELECT id, nome, tipo FROM veiculos WHERE usuario_id = :usuario_id ORDER BY nome');
$veiculosStmt->execute([':usuario_id' => $usuario['id']]);
$veiculos = $veiculosStmt->fetchAll();

$veiculoIdFiltro = filter_input(INPUT_GET, 'veiculo_id', FILTER_VALIDATE_INT) ?: null;
$filtroVeiculoSql = $veiculoIdFiltro !== null ? ' AND r.veiculo_id = :veiculo_id' : '';

$bind = function (PDOStatement $stmt) use ($usuario, $veiculoIdFiltro): void {
    $stmt->bindValue(':usuario_id', $usuario['id'], PDO::PARAM_INT);
    if ($veiculoIdFiltro !== null) {
        $stmt->bindValue(':veiculo_id', $veiculoIdFiltro, PDO::PARAM_INT);
    }
};

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

<?php if (count($veiculos) > 1): ?>
<form method="get" class="px-1 mb-3" id="formFiltroVeiculo">
    <select name="veiculo_id" class="form-select" id="selectVeiculoFiltro">
        <option value="">Todos os veículos</option>
        <?php foreach ($veiculos as $v): ?>
        <option value="<?= (int) $v['id'] ?>" <?= $veiculoIdFiltro === (int) $v['id'] ? 'selected' : '' ?>>
            <?= h($v['nome']) ?> (<?= h($v['tipo']) ?>)
        </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<div class="row gx-2 px-1 mb-3">
    <div class="col-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <p class="text-muted small mb-1">Total Gasto</p>
                <p class="fw-bold mb-0 small"><?= h(formatarMoeda($totalGasto)) ?></p>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <p class="text-muted small mb-1">Gasto Médio/Dia</p>
                <p class="fw-bold mb-0 small"><?= $gastoMedioDia !== null ? h(formatarMoeda($gastoMedioDia)) : '—' ?></p>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <p class="text-muted small mb-1">Preço Médio/L</p>
                <p class="fw-bold mb-0 small"><?= $precoMedioLitro !== null ? h(formatarMoeda($precoMedioLitro)) : '—' ?></p>
            </div>
        </div>
    </div>
</div>

<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Gasto por Mês</h6>
    <?php if (!$gastoPorMes): ?>
        <p class="text-center text-muted small py-3">Sem dados suficientes ainda.</p>
    <?php else: ?>
        <div class="card shadow-sm border-0"><div class="card-body"><canvas id="graficoGastoMes" height="180"></canvas></div></div>
    <?php endif; ?>
</div>

<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Km Rodado por Mês</h6>
    <?php if (!$kmPorMes): ?>
        <p class="text-center text-muted small py-3">Sem dados suficientes ainda.</p>
    <?php else: ?>
        <div class="card shadow-sm border-0"><div class="card-body"><canvas id="graficoKmMes" height="180"></canvas></div></div>
    <?php endif; ?>
</div>

<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Evolução do Consumo (km/l)</h6>
    <?php if (!$consumo): ?>
        <p class="text-center text-muted small py-3">Precisa de pelo menos 2 abastecimentos do mesmo veículo.</p>
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
