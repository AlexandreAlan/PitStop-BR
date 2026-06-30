<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$erros = [];
$nome = '';
$tipo = '';
$tiposPermitidos = ['Moto', 'Carro', 'Outro'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $nome = trim((string) ($_POST['nome'] ?? ''));
    $tipo = (string) ($_POST['tipo'] ?? '');

    if ($nome === '' || mb_strlen($nome) > 100) {
        $erros[] = 'Nome do veículo inválido (máx. 100 caracteres).';
    }
    if (!in_array($tipo, $tiposPermitidos, true)) {
        $erros[] = 'Tipo de veículo inválido.';
    }

    if (!$erros) {
        $stmt = $pdo->prepare('INSERT INTO veiculos (nome, tipo) VALUES (:nome, :tipo)');
        $stmt->execute([':nome' => $nome, ':tipo' => $tipo]);

        flashSet('sucesso', 'Veículo cadastrado com sucesso.');
        header('Location: veiculos.php');
        exit;
    }
}

$veiculos = $pdo->query('SELECT id, nome, tipo, criado_em FROM veiculos ORDER BY nome')->fetchAll();
$tituloPagina = 'Veículos — PitStop BR';
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

<div class="lista-veiculos px-1 mb-4">
    <h6 class="text-muted mb-2 px-1">Meus Veículos</h6>
    <?php if (!$veiculos): ?>
        <p class="text-center text-muted small py-3">Nenhum veículo cadastrado.</p>
    <?php else: ?>
        <?php foreach ($veiculos as $v): ?>
        <div class="card shadow-sm mb-2">
            <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold"><?= h($v['nome']) ?></div>
                    <div class="text-muted small"><?= h($v['tipo']) ?></div>
                </div>
                <i class="bi <?= $v['tipo'] === 'Moto' ? 'bi-bicycle' : 'bi-car-front' ?> fs-4 text-muted"></i>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<form method="post" action="veiculos.php" class="px-1" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

    <h6 class="text-muted mb-3 px-1">Adicionar Veículo</h6>

    <div class="mb-3">
        <label class="form-label">Nome</label>
        <input type="text" name="nome" maxlength="100" class="form-control form-control-lg" value="<?= h($nome) ?>" placeholder="Ex: Honda Bros 2020" required>
    </div>

    <div class="mb-4">
        <label class="form-label">Tipo</label>
        <select name="tipo" class="form-select form-select-lg" required>
            <option value="">Selecione...</option>
            <?php foreach ($tiposPermitidos as $t): ?>
            <option value="<?= h($t) ?>" <?= $tipo === $t ? 'selected' : '' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100 mb-4">
        <i class="bi bi-check-lg me-1"></i>Salvar Veículo
    </button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
