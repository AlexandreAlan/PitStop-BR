<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$veiculosStmt = $pdo->prepare('SELECT id, nome, tipo FROM veiculos WHERE usuario_id = :usuario_id ORDER BY nome');
$veiculosStmt->execute([':usuario_id' => $usuario['id']]);
$veiculos = $veiculosStmt->fetchAll();

$erros = [];
$dados = [
    'veiculo_id' => '',
    'descricao'  => '',
    'tipo_alvo'  => 'KM',
    'km_alvo'    => '',
    'data_alvo'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $dados['veiculo_id'] = (string) ($_POST['veiculo_id'] ?? '');
    $dados['descricao']  = trim((string) ($_POST['descricao'] ?? ''));
    $dados['tipo_alvo']  = (string) ($_POST['tipo_alvo'] ?? '');
    $dados['km_alvo']    = (string) ($_POST['km_alvo'] ?? '');
    $dados['data_alvo']  = (string) ($_POST['data_alvo'] ?? '');

    $veiculoId = filter_var($dados['veiculo_id'], FILTER_VALIDATE_INT);
    $tipoAlvo  = in_array($dados['tipo_alvo'], ['KM', 'Data'], true) ? $dados['tipo_alvo'] : null;
    $kmAlvo    = $dados['km_alvo'] === '' ? null : filter_var($dados['km_alvo'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $dataAlvo  = $dados['data_alvo'] === '' ? null : DateTime::createFromFormat('Y-m-d', $dados['data_alvo']);

    if (!$veiculoId) {
        $erros[] = 'Selecione um veículo válido.';
    } else {
        $existe = $pdo->prepare('SELECT 1 FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
        $existe->execute([':id' => $veiculoId, ':usuario_id' => $usuario['id']]);
        if (!$existe->fetchColumn()) {
            $erros[] = 'Veículo não encontrado.';
        }
    }
    if ($dados['descricao'] === '' || mb_strlen($dados['descricao']) > 150) {
        $erros[] = 'Descrição inválida (máx. 150 caracteres).';
    }
    if (!$tipoAlvo) {
        $erros[] = 'Selecione se o lembrete é por km ou por data.';
    }
    if ($tipoAlvo === 'KM' && !$kmAlvo) {
        $erros[] = 'Informe o km alvo do lembrete.';
    }
    if ($tipoAlvo === 'Data' && (!$dataAlvo || $dataAlvo->format('Y-m-d') !== $dados['data_alvo'])) {
        $erros[] = 'Informe uma data alvo válida.';
    }

    if (!$erros) {
        $stmt = $pdo->prepare(
            'INSERT INTO lembretes (veiculo_id, descricao, tipo_alvo, km_alvo, data_alvo)
             VALUES (:veiculo_id, :descricao, :tipo_alvo, :km_alvo, :data_alvo)'
        );
        $stmt->execute([
            ':veiculo_id' => $veiculoId,
            ':descricao'  => $dados['descricao'],
            ':tipo_alvo'  => $tipoAlvo,
            ':km_alvo'    => $tipoAlvo === 'KM' ? $kmAlvo : null,
            ':data_alvo'  => $tipoAlvo === 'Data' ? $dataAlvo->format('Y-m-d') : null,
        ]);

        flashSet('sucesso', 'Lembrete criado com sucesso.');
        header('Location: lembretes.php');
        exit;
    }
}

$lembretesStmt = $pdo->prepare(
    "SELECT l.id, l.veiculo_id, l.descricao, l.tipo_alvo, l.km_alvo, l.data_alvo, v.nome AS veiculo_nome,
            (SELECT MAX(r.km_atual) FROM registros r WHERE r.veiculo_id = l.veiculo_id) AS km_atual_veiculo
     FROM lembretes l
     INNER JOIN veiculos v ON v.id = l.veiculo_id
     WHERE v.usuario_id = :usuario_id AND l.concluido_em IS NULL
     ORDER BY l.criado_em DESC"
);
$lembretesStmt->execute([':usuario_id' => $usuario['id']]);
$lembretes = $lembretesStmt->fetchAll();

$prioridadeStatus = ['vencido' => 0, 'proximo' => 1, 'ok' => 2];
usort($lembretes, static function ($a, $b) use ($prioridadeStatus) {
    $statusA = calcularStatusLembrete($a)['status'];
    $statusB = calcularStatusLembrete($b)['status'];
    return $prioridadeStatus[$statusA] <=> $prioridadeStatus[$statusB];
});

$tituloPagina = 'Lembretes — PitStop BR';
$mostrarVoltar = true;
require __DIR__ . '/includes/header.php';
?>

<?php if ($erros): ?>
<div class="alert alert-danger py-2">
    <ul class="mb-0 ps-3 small">
        <?php foreach ($erros as $erro): ?><li><?= h($erro) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="lista-lembretes px-1 mb-4">
    <h6 class="text-muted mb-2 px-1">Meus Lembretes</h6>
    <?php if (!$lembretes): ?>
        <div class="estado-vazio">
            <i class="bi bi-bell estado-vazio-icone" aria-hidden="true"></i>
            <p class="estado-vazio-titulo">Nenhum lembrete cadastrado</p>
            <p class="estado-vazio-texto">Cadastre lembretes de troca de óleo, revisão, seguro e outras manutenções por km ou por data.</p>
        </div>
    <?php else: ?>
        <?php foreach ($lembretes as $l): $st = calcularStatusLembrete($l); ?>
        <div class="card shadow-sm mb-2">
            <div class="card-body py-2 px-3 d-flex justify-content-between align-items-start">
                <div class="registro-info">
                    <span class="badge <?= $st['classe'] ?> mb-1"><?= h($st['rotulo']) ?></span>
                    <div class="fw-semibold"><?= h($l['descricao']) ?></div>
                    <div class="text-muted small">
                        <?= h($l['veiculo_nome']) ?> ·
                        <?php if ($l['tipo_alvo'] === 'KM'): ?>
                            aos <?= number_format((float) $l['km_alvo'], 0, ',', '.') ?> km
                        <?php else: ?>
                            <?= h((new DateTime($l['data_alvo']))->format('d/m/Y')) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end registro-valor-col d-flex flex-column gap-1">
                    <form method="post" action="lembrete_concluir.php" class="form-lembrete-concluir">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success py-0 px-1" title="Marcar como feito">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                    <form method="post" action="lembrete_excluir.php" class="form-excluir-lembrete">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Excluir">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<form method="post" action="lembretes.php" class="px-1" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

    <h6 class="text-muted mb-3 px-1">Novo Lembrete</h6>

    <?php if (!$veiculos): ?>
    <div class="alert alert-warning">
        Cadastre um <a href="veiculos.php">veículo</a> antes de criar um lembrete.
    </div>
    <?php else: ?>

    <div class="mb-3">
        <label class="form-label">Veículo</label>
        <select name="veiculo_id" class="form-select form-select-lg" required>
            <option value="">Selecione...</option>
            <?php foreach ($veiculos as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= (string) $v['id'] === $dados['veiculo_id'] ? 'selected' : '' ?>>
                <?= h($v['nome']) ?> (<?= h($v['tipo']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Descrição</label>
        <input type="text" name="descricao" maxlength="150" class="form-control form-control-lg" value="<?= h($dados['descricao']) ?>" placeholder="Ex: Troca de óleo" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Lembrar por</label>
        <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="tipo_alvo" id="alvoKm" value="KM" <?= $dados['tipo_alvo'] === 'KM' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="alvoKm"><i class="bi bi-speedometer2 me-1"></i>Km</label>

            <input type="radio" class="btn-check" name="tipo_alvo" id="alvoData" value="Data" <?= $dados['tipo_alvo'] === 'Data' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="alvoData"><i class="bi bi-calendar-event me-1"></i>Data</label>
        </div>
    </div>

    <div class="mb-3 campo-alvo-km <?= $dados['tipo_alvo'] !== 'KM' ? 'd-none' : '' ?>">
        <label class="form-label">KM Alvo</label>
        <input type="number" name="km_alvo" class="form-control form-control-lg" min="1" inputmode="numeric" value="<?= h($dados['km_alvo']) ?>">
    </div>

    <div class="mb-4 campo-alvo-data <?= $dados['tipo_alvo'] !== 'Data' ? 'd-none' : '' ?>">
        <label class="form-label">Data Alvo</label>
        <input type="date" name="data_alvo" class="form-control form-control-lg" value="<?= h($dados['data_alvo']) ?>">
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100 mb-4">
        <i class="bi bi-check-lg me-1"></i>Salvar Lembrete
    </button>
    <?php endif; ?>
</form>

<script src="assets/js/lembretes.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
