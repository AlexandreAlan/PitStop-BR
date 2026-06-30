<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$veiculosStmt = $pdo->prepare('SELECT id, nome, tipo FROM veiculos WHERE usuario_id = :usuario_id ORDER BY nome');
$veiculosStmt->execute([':usuario_id' => $usuario['id']]);
$veiculos = $veiculosStmt->fetchAll();

$veiculoIdFiltro = filter_input(INPUT_GET, 'veiculo_id', FILTER_VALIDATE_INT) ?: null;

$ultimaMedia = calcularUltimaMedia($pdo, $usuario['id'], $veiculoIdFiltro);

$sqlRegistros = 'SELECT r.id, r.data, r.km_atual, r.tipo_registro, r.litros, r.valor_pago, r.descricao, v.nome AS veiculo_nome
                  FROM registros r
                  INNER JOIN veiculos v ON v.id = r.veiculo_id
                  WHERE v.usuario_id = :usuario_id'
    . ($veiculoIdFiltro !== null ? ' AND r.veiculo_id = :veiculo_id' : '')
    . ' ORDER BY r.data DESC, r.id DESC LIMIT 10';

$stmt = $pdo->prepare($sqlRegistros);
$stmt->bindValue(':usuario_id', $usuario['id'], PDO::PARAM_INT);
if ($veiculoIdFiltro !== null) {
    $stmt->bindValue(':veiculo_id', $veiculoIdFiltro, PDO::PARAM_INT);
}
$stmt->execute();
$registros = $stmt->fetchAll();

$sqlGastoMes = 'SELECT COALESCE(SUM(r.valor_pago), 0)
                 FROM registros r
                 INNER JOIN veiculos v ON v.id = r.veiculo_id
                 WHERE v.usuario_id = :usuario_id
                   AND DATE_FORMAT(r.data, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")'
    . ($veiculoIdFiltro !== null ? ' AND r.veiculo_id = :veiculo_id' : '');
$stmt = $pdo->prepare($sqlGastoMes);
$stmt->bindValue(':usuario_id', $usuario['id'], PDO::PARAM_INT);
if ($veiculoIdFiltro !== null) {
    $stmt->bindValue(':veiculo_id', $veiculoIdFiltro, PDO::PARAM_INT);
}
$stmt->execute();
$gastoMes = (float) $stmt->fetchColumn();

$tituloPagina = 'PitStop BR';
require __DIR__ . '/includes/header.php';
?>

<div class="card card-resumo shadow-sm">
    <div class="card-body text-center py-4">
        <p class="text-muted mb-1 small text-uppercase">Última Média</p>
        <h2 class="display-6 fw-bold text-success mb-0">
            <i class="bi bi-speedometer2 me-1"></i><?= $ultimaMedia !== null ? h(number_format($ultimaMedia, 1, ',', '.') . ' km/l') : 'Sem dados' ?>
        </h2>
        <p class="text-muted small mt-2 mb-0">Gasto este mês: <strong><?= h(formatarMoeda($gastoMes)) ?></strong></p>
    </div>
</div>

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

<div class="lista-registros px-1">
    <div class="d-flex justify-content-between align-items-center mb-2 px-1">
        <h6 class="text-muted mb-0">Registros Recentes</h6>
    </div>

    <?php if (!$registros): ?>
        <p class="text-center text-muted small py-4">Nenhum registro cadastrado ainda.</p>
    <?php else: ?>
        <?php foreach ($registros as $r): ?>
        <div class="card shadow-sm mb-2">
            <div class="card-body py-2 px-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="badge <?= $r['tipo_registro'] === 'Abastecimento' ? 'bg-success' : 'bg-warning text-dark' ?> mb-1">
                            <i class="bi <?= $r['tipo_registro'] === 'Abastecimento' ? 'bi-fuel-pump' : 'bi-tools' ?> me-1"></i><?= h($r['tipo_registro'] === 'Abastecimento' ? 'Abastecimento' : 'Manutenção') ?>
                        </span>
                        <div class="fw-semibold"><?= h($r['veiculo_nome']) ?></div>
                        <div class="text-muted small">
                            <?= h((new DateTime($r['data']))->format('d/m/Y')) ?> · <?= number_format((float) $r['km_atual'], 0, ',', '.') ?> km
                            <?php if ($r['litros']): ?> · <?= number_format((float) $r['litros'], 2, ',', '.') ?> L<?php endif; ?>
                        </div>
                        <?php if ($r['descricao']): ?><div class="text-muted small fst-italic"><?= h($r['descricao']) ?></div><?php endif; ?>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold mb-1"><?= h(formatarMoeda((float) $r['valor_pago'])) ?></div>
                        <a href="registro_editar.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-1">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="post" action="excluir.php" class="form-excluir d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<a href="adicionar.php" class="btn btn-primary fab" aria-label="Adicionar Registro">
    <i class="bi bi-plus-lg"></i>
</a>

<script src="assets/js/index.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
