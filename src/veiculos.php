<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$erros = [];
$nome = '';
$tipo = '';
$cor = '';
$placa = '';
$buscaModelo = '';
$modeloVeiculoId = '';
$tanqueLitros = '';
$pesoKg = '';
$tiposPermitidos = ['Moto', 'Carro', 'Outro'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $nome = trim((string) ($_POST['nome'] ?? ''));
    $tipo = (string) ($_POST['tipo'] ?? '');
    $cor = trim((string) ($_POST['cor'] ?? ''));
    $placa = strtoupper(trim((string) ($_POST['placa'] ?? '')));
    $buscaModelo = trim((string) ($_POST['busca_modelo'] ?? ''));
    $modeloVeiculoId = trim((string) ($_POST['modelo_veiculo_id'] ?? ''));
    $tanqueLitros = trim((string) ($_POST['tanque_litros'] ?? ''));
    $pesoKg = trim((string) ($_POST['peso_kg'] ?? ''));

    if ($nome === '' || mb_strlen($nome) > 100) {
        $erros[] = 'Nome do veículo inválido (máx. 100 caracteres).';
    }
    if (!in_array($tipo, $tiposPermitidos, true)) {
        $erros[] = 'Tipo de veículo inválido.';
    }
    if ($cor !== '' && mb_strlen($cor) > 30) {
        $erros[] = 'Cor inválida (máx. 30 caracteres).';
    }
    if ($placa !== '' && !preg_match('/^[A-Z0-9]{7,8}$/', $placa)) {
        $erros[] = 'Placa inválida.';
    }

    $modeloVeiculoIdValido = null;
    if ($modeloVeiculoId !== '') {
        $chk = $pdo->prepare('SELECT id FROM modelos_veiculos WHERE id = :id');
        $chk->execute([':id' => $modeloVeiculoId]);
        if ($chk->fetch()) {
            $modeloVeiculoIdValido = (int) $modeloVeiculoId;
        }
    }

    $tanqueLitrosValido = null;
    if ($tanqueLitros !== '') {
        if (!is_numeric($tanqueLitros) || (float) $tanqueLitros <= 0 || (float) $tanqueLitros > 999.99) {
            $erros[] = 'Capacidade do tanque inválida.';
        } else {
            $tanqueLitrosValido = (float) $tanqueLitros;
        }
    }

    $pesoKgValido = null;
    if ($pesoKg !== '') {
        if (!ctype_digit($pesoKg) || (int) $pesoKg <= 0 || (int) $pesoKg > 65000) {
            $erros[] = 'Peso do veículo inválido.';
        } else {
            $pesoKgValido = (int) $pesoKg;
        }
    }

    if (!$erros) {
        $stmt = $pdo->prepare(
            'INSERT INTO veiculos (usuario_id, nome, tipo, cor, placa, modelo_veiculo_id, tanque_litros, peso_kg)
             VALUES (:usuario_id, :nome, :tipo, :cor, :placa, :modelo_veiculo_id, :tanque_litros, :peso_kg)'
        );
        $stmt->execute([
            ':usuario_id' => $usuario['id'],
            ':nome' => $nome,
            ':tipo' => $tipo,
            ':cor' => $cor !== '' ? $cor : null,
            ':placa' => $placa !== '' ? $placa : null,
            ':modelo_veiculo_id' => $modeloVeiculoIdValido,
            ':tanque_litros' => $tanqueLitrosValido,
            ':peso_kg' => $pesoKgValido,
        ]);

        flashSet('sucesso', 'Veículo cadastrado com sucesso.');
        header('Location: veiculos.php');
        exit;
    }
}

$veiculosStmt = $pdo->prepare('SELECT id, nome, tipo, cor, placa, tanque_litros, peso_kg, criado_em FROM veiculos WHERE usuario_id = :usuario_id ORDER BY nome');
$veiculosStmt->execute([':usuario_id' => $usuario['id']]);
$veiculos = $veiculosStmt->fetchAll();

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
        <div class="estado-vazio">
            <i class="bi bi-car-front estado-vazio-icone" aria-hidden="true"></i>
            <p class="estado-vazio-titulo">Nenhum veículo cadastrado</p>
            <p class="estado-vazio-texto">Cadastre seu primeiro veículo no formulário abaixo pra começar a registrar abastecimentos e manutenções.</p>
        </div>
    <?php else: ?>
        <?php foreach ($veiculos as $v): ?>
        <div class="card shadow-sm mb-2">
            <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi <?= $v['tipo'] === 'Moto' ? 'bi-bicycle' : 'bi-car-front' ?> fs-4 text-muted"></i>
                    <div>
                        <div class="fw-semibold"><?= h($v['nome']) ?></div>
                        <div class="text-muted small">
                            <?= h($v['tipo']) ?><?= $v['cor'] ? ' · ' . h($v['cor']) : '' ?><?= $v['placa'] ? ' · ' . h($v['placa']) : '' ?>
                            <?php if ($v['tanque_litros']): ?> · tanque <?= h((string) $v['tanque_litros']) ?>L<?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="text-end">
                    <a href="veiculo_editar.php?id=<?= (int) $v['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-1">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" action="veiculo_excluir.php" class="form-excluir-veiculo d-inline">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<form method="post" action="veiculos.php" class="px-1" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

    <h6 class="text-muted mb-3 px-1">Adicionar Veículo</h6>

    <div class="mb-3 position-relative">
        <label class="form-label">Nome</label>
        <input type="text" id="nome" name="nome" maxlength="100" class="form-control form-control-lg" value="<?= h($nome) ?>" placeholder="Ex: Honda Bros 2020" autocomplete="off" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Tipo</label>
        <select name="tipo" class="form-select form-select-lg" required>
            <option value="">Selecione...</option>
            <?php foreach ($tiposPermitidos as $t): ?>
            <option value="<?= h($t) ?>" <?= $tipo === $t ? 'selected' : '' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6">
            <label class="form-label">Cor</label>
            <input type="text" name="cor" maxlength="30" class="form-control form-control-lg" value="<?= h($cor) ?>" placeholder="Ex: Preto">
        </div>
        <div class="col-6">
            <label class="form-label">Placa</label>
            <input type="text" name="placa" maxlength="8" class="form-control form-control-lg text-uppercase" value="<?= h($placa) ?>" placeholder="ABC1D23">
        </div>
    </div>

    <div class="mb-2 position-relative">
        <label class="form-label">Buscar modelo (autopreenche tanque e peso)</label>
        <input type="text" id="buscaModelo" name="busca_modelo" class="form-control form-control-lg" value="<?= h($buscaModelo) ?>" placeholder="Ex: Bros 160 2025" autocomplete="off">
        <input type="hidden" id="modeloVeiculoId" name="modelo_veiculo_id" value="<?= h($modeloVeiculoId) ?>">
        <div id="buscaModeloResultados" class="list-group d-none position-absolute w-100 shadow-sm" style="z-index: 1050; max-height: 260px; overflow-y: auto;"></div>
        <div class="form-text">Não achou o seu? Sem problema, preenche tanque e peso à mão abaixo.</div>
    </div>

    <div class="row g-2 mb-4">
        <div class="col-6">
            <label class="form-label">Tanque (L)</label>
            <input type="number" step="0.1" min="0.1" max="999.99" id="tanqueLitros" name="tanque_litros" class="form-control form-control-lg" value="<?= h($tanqueLitros) ?>">
        </div>
        <div class="col-6">
            <label class="form-label">Peso (kg)</label>
            <input type="number" min="1" max="65000" id="pesoKg" name="peso_kg" class="form-control form-control-lg" value="<?= h($pesoKg) ?>">
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100 mb-4">
        <i class="bi bi-check-lg me-1"></i>Salvar Veículo
    </button>
</form>

<script src="assets/js/veiculos.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
