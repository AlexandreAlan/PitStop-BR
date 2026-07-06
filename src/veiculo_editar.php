<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$tiposPermitidos = ['Moto', 'Carro', 'Outro'];

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    die('ID inválido.');
}

$stmt = $pdo->prepare(
    'SELECT v.id, v.nome, v.tipo, v.cor, v.placa, v.modelo_veiculo_id, v.tanque_litros, v.peso_kg,
            m.marca, m.modelo
     FROM veiculos v
     LEFT JOIN modelos_veiculos m ON m.id = v.modelo_veiculo_id
     WHERE v.id = :id AND v.usuario_id = :usuario_id'
);
$stmt->execute([':id' => $id, ':usuario_id' => $usuario['id']]);
$veiculo = $stmt->fetch();

if (!$veiculo) {
    http_response_code(404);
    die('Veículo não encontrado.');
}

$erros = [];
$nome = $veiculo['nome'];
$tipo = $veiculo['tipo'];
$cor = (string) ($veiculo['cor'] ?? '');
$placa = (string) ($veiculo['placa'] ?? '');
$modeloVeiculoId = $veiculo['modelo_veiculo_id'] !== null ? (string) $veiculo['modelo_veiculo_id'] : '';
$buscaModelo = $veiculo['marca'] ? $veiculo['marca'] . ' ' . $veiculo['modelo'] : '';
$tanqueLitros = $veiculo['tanque_litros'] !== null ? (string) $veiculo['tanque_litros'] : '';
$pesoKg = $veiculo['peso_kg'] !== null ? (string) $veiculo['peso_kg'] : '';

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
        $upd = $pdo->prepare(
            'UPDATE veiculos SET nome = :nome, tipo = :tipo, cor = :cor, placa = :placa,
                modelo_veiculo_id = :modelo_veiculo_id, tanque_litros = :tanque_litros, peso_kg = :peso_kg
             WHERE id = :id AND usuario_id = :usuario_id'
        );
        $upd->execute([
            ':nome' => $nome,
            ':tipo' => $tipo,
            ':cor' => $cor !== '' ? $cor : null,
            ':placa' => $placa !== '' ? $placa : null,
            ':modelo_veiculo_id' => $modeloVeiculoIdValido,
            ':tanque_litros' => $tanqueLitrosValido,
            ':peso_kg' => $pesoKgValido,
            ':id' => $id,
            ':usuario_id' => $usuario['id'],
        ]);

        flashSet('sucesso', 'Veículo atualizado com sucesso.');
        header('Location: veiculos.php');
        exit;
    }
}

$tituloPagina = 'Editar Veículo — PitStop BR';
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

<form method="post" action="veiculo_editar.php?id=<?= (int) $id ?>" class="px-1" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="id" value="<?= (int) $id ?>">

    <div class="mb-3 position-relative">
        <label class="form-label">Nome</label>
        <input type="text" id="nome" name="nome" maxlength="100" class="form-control form-control-lg" value="<?= h($nome) ?>" autocomplete="off" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Tipo</label>
        <select name="tipo" class="form-select form-select-lg" required>
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
        <i class="bi bi-check-lg me-1"></i>Salvar Alterações
    </button>
</form>

<script src="assets/js/veiculos.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
