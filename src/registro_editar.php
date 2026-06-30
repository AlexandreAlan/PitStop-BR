<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    die('ID inválido.');
}

$stmt = $pdo->prepare(
    'SELECT r.id, r.veiculo_id, r.data, r.km_atual, r.tipo_registro, r.combustivel, r.litros, r.valor_pago, r.descricao
     FROM registros r
     INNER JOIN veiculos v ON v.id = r.veiculo_id
     WHERE r.id = :id AND v.usuario_id = :usuario_id'
);
$stmt->execute([':id' => $id, ':usuario_id' => $usuario['id']]);
$registro = $stmt->fetch();

if (!$registro) {
    http_response_code(404);
    die('Registro não encontrado.');
}

$veiculosStmt = $pdo->prepare('SELECT id, nome, tipo FROM veiculos WHERE usuario_id = :usuario_id ORDER BY nome');
$veiculosStmt->execute([':usuario_id' => $usuario['id']]);
$veiculos = $veiculosStmt->fetchAll();

$combustiveisPermitidos = ['Gasolina Comum', 'Gasolina Aditivada', 'Etanol', 'Diesel', 'GNV', 'Outro'];

$erros = [];
$dados = [
    'veiculo_id'    => (string) $registro['veiculo_id'],
    'data'          => $registro['data'],
    'km_atual'      => (string) $registro['km_atual'],
    'tipo_registro' => $registro['tipo_registro'],
    'combustivel'   => (string) $registro['combustivel'],
    'litros'        => $registro['litros'] !== null ? (string) $registro['litros'] : '',
    'valor_pago'    => (string) $registro['valor_pago'],
    'descricao'     => (string) $registro['descricao'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $dados['veiculo_id']    = (string) ($_POST['veiculo_id'] ?? '');
    $dados['data']          = (string) ($_POST['data'] ?? '');
    $dados['km_atual']      = (string) ($_POST['km_atual'] ?? '');
    $dados['tipo_registro'] = (string) ($_POST['tipo_registro'] ?? '');
    $dados['combustivel']   = (string) ($_POST['combustivel'] ?? '');
    $dados['litros']        = (string) ($_POST['litros'] ?? '');
    $dados['valor_pago']    = (string) ($_POST['valor_pago'] ?? '');
    $dados['descricao']     = trim((string) ($_POST['descricao'] ?? ''));

    $veiculoId     = filter_var($dados['veiculo_id'], FILTER_VALIDATE_INT);
    $kmAtual       = filter_var($dados['km_atual'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $tipoRegistro  = in_array($dados['tipo_registro'], ['Abastecimento', 'Manutencao'], true) ? $dados['tipo_registro'] : null;
    $valorPago     = filter_var($dados['valor_pago'], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
    $litros        = $dados['litros'] === '' ? null : filter_var($dados['litros'], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.01]]);
    $combustivel   = in_array($dados['combustivel'], $combustiveisPermitidos, true) ? $dados['combustivel'] : null;
    $dataRegistro  = DateTime::createFromFormat('Y-m-d', $dados['data']);

    if (!$veiculoId) {
        $erros[] = 'Selecione um veículo válido.';
    } else {
        $existe = $pdo->prepare('SELECT 1 FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
        $existe->execute([':id' => $veiculoId, ':usuario_id' => $usuario['id']]);
        if (!$existe->fetchColumn()) {
            $erros[] = 'Veículo não encontrado.';
        }
    }
    if (!$dataRegistro || $dataRegistro->format('Y-m-d') !== $dados['data']) {
        $erros[] = 'Data inválida.';
    }
    if ($kmAtual === false || $kmAtual === null) {
        $erros[] = 'KM atual inválido.';
    }
    if (!$tipoRegistro) {
        $erros[] = 'Tipo de registro inválido.';
    }
    if ($valorPago === false || $valorPago === null) {
        $erros[] = 'Valor pago inválido.';
    }
    if ($tipoRegistro === 'Abastecimento' && !$litros) {
        $erros[] = 'Informe os litros abastecidos.';
    }
    if ($tipoRegistro === 'Abastecimento' && !$combustivel) {
        $erros[] = 'Selecione o combustível.';
    }
    if (mb_strlen($dados['descricao']) > 255) {
        $erros[] = 'Descrição muito longa (máx. 255 caracteres).';
    }

    if (!$erros) {
        $upd = $pdo->prepare(
            'UPDATE registros r
             INNER JOIN veiculos v ON v.id = r.veiculo_id
             SET r.veiculo_id = :veiculo_id, r.data = :data, r.km_atual = :km_atual,
                 r.tipo_registro = :tipo_registro, r.combustivel = :combustivel, r.litros = :litros,
                 r.valor_pago = :valor_pago, r.descricao = :descricao
             WHERE r.id = :id AND v.usuario_id = :usuario_id'
        );
        $upd->execute([
            ':veiculo_id'    => $veiculoId,
            ':data'          => $dataRegistro->format('Y-m-d'),
            ':km_atual'      => $kmAtual,
            ':tipo_registro' => $tipoRegistro,
            ':combustivel'   => $tipoRegistro === 'Abastecimento' ? $combustivel : null,
            ':litros'        => $tipoRegistro === 'Abastecimento' ? $litros : null,
            ':valor_pago'    => $valorPago,
            ':descricao'     => $dados['descricao'] !== '' ? $dados['descricao'] : null,
            ':id'            => $id,
            ':usuario_id'    => $usuario['id'],
        ]);

        flashSet('sucesso', 'Registro atualizado com sucesso.');
        header('Location: index.php');
        exit;
    }
}

$tituloPagina = 'Editar Registro — PitStop BR';
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

<form method="post" action="registro_editar.php?id=<?= (int) $id ?>" class="px-1" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="id" value="<?= (int) $id ?>">

    <div class="mb-3">
        <label class="form-label">Veículo</label>
        <select name="veiculo_id" class="form-select form-select-lg" required>
            <?php foreach ($veiculos as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= (string) $v['id'] === $dados['veiculo_id'] ? 'selected' : '' ?>>
                <?= h($v['nome']) ?> (<?= h($v['tipo']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Tipo de Registro</label>
        <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="tipo_registro" id="tipoAbastecimento" value="Abastecimento" <?= $dados['tipo_registro'] === 'Abastecimento' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary btn-lg" for="tipoAbastecimento"><i class="bi bi-fuel-pump me-1"></i>Abastecimento</label>

            <input type="radio" class="btn-check" name="tipo_registro" id="tipoManutencao" value="Manutencao" <?= $dados['tipo_registro'] === 'Manutencao' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary btn-lg" for="tipoManutencao"><i class="bi bi-tools me-1"></i>Manutenção</label>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label">Data</label>
        <input type="date" name="data" class="form-control form-control-lg" value="<?= h($dados['data']) ?>" max="<?= date('Y-m-d') ?>" required>
    </div>

    <div class="mb-3">
        <label class="form-label">KM Atual</label>
        <input type="number" name="km_atual" class="form-control form-control-lg" min="0" inputmode="numeric" value="<?= h($dados['km_atual']) ?>" required>
    </div>

    <div class="mb-3 campo-abastecimento <?= $dados['tipo_registro'] === 'Manutencao' ? 'd-none' : '' ?>">
        <label class="form-label">Combustível</label>
        <select name="combustivel" class="form-select form-select-lg">
            <option value="">Selecione...</option>
            <?php foreach ($combustiveisPermitidos as $c): ?>
            <option value="<?= h($c) ?>" <?= $dados['combustivel'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3 campo-abastecimento <?= $dados['tipo_registro'] === 'Manutencao' ? 'd-none' : '' ?>">
        <label class="form-label">Litros Abastecidos</label>
        <input type="number" step="0.01" min="0.01" name="litros" class="form-control form-control-lg" inputmode="decimal" value="<?= h($dados['litros']) ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Valor Pago (R$)</label>
        <input type="number" step="0.01" min="0" name="valor_pago" class="form-control form-control-lg" inputmode="decimal" value="<?= h($dados['valor_pago']) ?>" required>
    </div>

    <div class="mb-4">
        <label class="form-label">Descrição (opcional)</label>
        <input type="text" name="descricao" maxlength="255" class="form-control form-control-lg" value="<?= h($dados['descricao']) ?>" placeholder="Ex: Troca de óleo">
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100 mb-4">
        <i class="bi bi-check-lg me-1"></i>Salvar Alterações
    </button>
</form>

<script src="assets/js/adicionar.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
