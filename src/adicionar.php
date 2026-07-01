<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$combustiveisPermitidos = COMBUSTIVEIS_PERMITIDOS;
$categoriasDespesaPermitidas = CATEGORIAS_DESPESA_PERMITIDAS;

$erros = [];
$dados = [
    'veiculo_id'        => '',
    'data'              => date('Y-m-d'),
    'km_atual'          => '',
    'tipo_registro'     => 'Abastecimento',
    'combustivel'       => '',
    'litros'            => '',
    'categoria_despesa' => '',
    'valor_pago'        => '',
    'descricao'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $dados['veiculo_id']        = (string) ($_POST['veiculo_id'] ?? '');
    $dados['data']              = (string) ($_POST['data'] ?? '');
    $dados['km_atual']          = (string) ($_POST['km_atual'] ?? '');
    $dados['tipo_registro']     = (string) ($_POST['tipo_registro'] ?? '');
    $dados['combustivel']       = (string) ($_POST['combustivel'] ?? '');
    $dados['litros']            = (string) ($_POST['litros'] ?? '');
    $dados['categoria_despesa'] = (string) ($_POST['categoria_despesa'] ?? '');
    $dados['valor_pago']        = (string) ($_POST['valor_pago'] ?? '');
    $dados['descricao']         = trim((string) ($_POST['descricao'] ?? ''));

    $resultado = validarRegistro($pdo, $usuario['id'], $dados);
    if ($resultado['ok']) {
        inserirRegistro($pdo, $resultado['valores']);
        flashSet('sucesso', 'Registro salvo com sucesso.');
        header('Location: index.php');
        exit;
    }
    $erros = $resultado['erros'];
}

$veiculosStmt = $pdo->prepare('SELECT id, nome, tipo FROM veiculos WHERE usuario_id = :usuario_id ORDER BY nome');
$veiculosStmt->execute([':usuario_id' => $usuario['id']]);
$veiculos = $veiculosStmt->fetchAll();
$tituloPagina = 'Adicionar Registro — PitStop BR';
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

<?php if (!$veiculos): ?>
<div class="alert alert-warning">
    Cadastre um <a href="veiculos.php">veículo</a> antes de adicionar um registro.
</div>
<?php else: ?>
<form method="post" action="adicionar.php" class="px-1" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

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
        <label class="form-label">Tipo de Registro</label>
        <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="tipo_registro" id="tipoAbastecimento" value="Abastecimento" <?= $dados['tipo_registro'] === 'Abastecimento' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="tipoAbastecimento"><i class="bi bi-fuel-pump me-1"></i>Abastecimento</label>

            <input type="radio" class="btn-check" name="tipo_registro" id="tipoManutencao" value="Manutencao" <?= $dados['tipo_registro'] === 'Manutencao' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="tipoManutencao"><i class="bi bi-tools me-1"></i>Manutenção</label>

            <input type="radio" class="btn-check" name="tipo_registro" id="tipoDespesa" value="Despesa" <?= $dados['tipo_registro'] === 'Despesa' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="tipoDespesa"><i class="bi bi-receipt me-1"></i>Despesa</label>
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

    <div class="mb-3 campo-abastecimento <?= $dados['tipo_registro'] !== 'Abastecimento' ? 'd-none' : '' ?>">
        <label class="form-label">Combustível</label>
        <select name="combustivel" class="form-select form-select-lg">
            <option value="">Selecione...</option>
            <?php foreach ($combustiveisPermitidos as $c): ?>
            <option value="<?= h($c) ?>" <?= $dados['combustivel'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3 campo-abastecimento <?= $dados['tipo_registro'] !== 'Abastecimento' ? 'd-none' : '' ?>">
        <label class="form-label">Litros Abastecidos</label>
        <input type="number" step="0.01" min="0.01" name="litros" class="form-control form-control-lg" inputmode="decimal" value="<?= h($dados['litros']) ?>">
    </div>

    <div class="mb-3 campo-despesa <?= $dados['tipo_registro'] !== 'Despesa' ? 'd-none' : '' ?>">
        <label class="form-label">Categoria da Despesa</label>
        <select name="categoria_despesa" class="form-select form-select-lg">
            <option value="">Selecione...</option>
            <?php foreach ($categoriasDespesaPermitidas as $c): ?>
            <option value="<?= h($c) ?>" <?= $dados['categoria_despesa'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
        </select>
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
        <i class="bi bi-check-lg me-1"></i>Salvar Registro
    </button>
</form>
<?php endif; ?>

<script src="assets/js/adicionar.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
