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

$stmt = $pdo->prepare('SELECT id, nome, tipo FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
$stmt->execute([':id' => $id, ':usuario_id' => $usuario['id']]);
$veiculo = $stmt->fetch();

if (!$veiculo) {
    http_response_code(404);
    die('Veículo não encontrado.');
}

$erros = [];
$nome = $veiculo['nome'];
$tipo = $veiculo['tipo'];

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
        $upd = $pdo->prepare('UPDATE veiculos SET nome = :nome, tipo = :tipo WHERE id = :id AND usuario_id = :usuario_id');
        $upd->execute([':nome' => $nome, ':tipo' => $tipo, ':id' => $id, ':usuario_id' => $usuario['id']]);

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

    <div class="mb-3">
        <label class="form-label">Nome</label>
        <input type="text" name="nome" maxlength="100" class="form-control form-control-lg" value="<?= h($nome) ?>" required>
    </div>

    <div class="mb-4">
        <label class="form-label">Tipo</label>
        <select name="tipo" class="form-select form-select-lg" required>
            <?php foreach ($tiposPermitidos as $t): ?>
            <option value="<?= h($t) ?>" <?= $tipo === $t ? 'selected' : '' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100 mb-4">
        <i class="bi bi-check-lg me-1"></i>Salvar Alterações
    </button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
