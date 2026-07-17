<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$erros = [];
$nome = '';
$localizacao = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $acao = (string) ($_POST['acao'] ?? 'criar');

    if ($acao === 'favoritar') {
        $postoId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($postoId) {
            alternarFavoritoPosto($pdo, $usuario['id'], $postoId);
        }
        header('Location: postos.php');
        exit;
    }

    $nome = trim((string) ($_POST['nome'] ?? ''));
    $localizacao = trim((string) ($_POST['localizacao'] ?? ''));

    $resultado = criarPosto($pdo, $usuario['id'], $nome, $localizacao);
    if ($resultado['ok']) {
        flashSet('sucesso', 'Posto cadastrado com sucesso.');
        header('Location: postos.php');
        exit;
    }
    $erros[] = $resultado['erro'];
}

$postos = listarPostos($pdo, $usuario['id']);

$tituloPagina = 'Postos — PitStop BR';
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
    <h6 class="text-muted mb-2 px-1">Meus Postos</h6>
    <?php if (!$postos): ?>
        <div class="estado-vazio">
            <i class="bi bi-signpost estado-vazio-icone" aria-hidden="true"></i>
            <p class="estado-vazio-titulo">Nenhum posto cadastrado</p>
            <p class="estado-vazio-texto">Cadastre os postos onde você costuma abastecer pra comparar o preço médio entre eles nos relatórios.</p>
        </div>
    <?php else: ?>
        <?php foreach ($postos as $p): ?>
        <div class="card shadow-sm mb-2">
            <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-signpost fs-4 text-muted"></i>
                    <div>
                        <div class="fw-semibold"><?= h($p['nome']) ?></div>
                        <?php if ($p['localizacao']): ?><div class="text-muted small"><?= h($p['localizacao']) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <form method="post" action="postos.php" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="acao" value="favoritar">
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <button type="submit" class="btn btn-sm py-0 px-1 <?= $p['favorito'] ? 'btn-warning' : 'btn-outline-secondary' ?>" title="<?= $p['favorito'] ? 'Remover dos favoritos' : 'Marcar como favorito' ?>">
                            <i class="bi <?= $p['favorito'] ? 'bi-star-fill' : 'bi-star' ?>"></i>
                        </button>
                    </form>
                    <form method="post" action="posto_excluir.php" class="form-excluir-posto d-inline">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
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

<form method="post" action="postos.php" class="px-1" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="acao" value="criar">

    <h6 class="text-muted mb-3 px-1">Adicionar Posto</h6>

    <div class="mb-3">
        <label class="form-label">Nome</label>
        <input type="text" name="nome" maxlength="100" class="form-control form-control-lg" value="<?= h($nome) ?>" placeholder="Ex: Posto Ipiranga da Av. Brasil" required>
    </div>

    <div class="mb-4">
        <label class="form-label">Localização (opcional)</label>
        <input type="text" name="localizacao" maxlength="255" class="form-control form-control-lg" value="<?= h($localizacao) ?>" placeholder="Ex: Av. Brasil, 1000">
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100 mb-4">
        <i class="bi bi-check-lg me-1"></i>Salvar Posto
    </button>
</form>

<script src="assets/js/postos.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
