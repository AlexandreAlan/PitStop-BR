<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$veiculoId = filter_input(INPUT_GET, 'veiculo_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'veiculo_id', FILTER_VALIDATE_INT);
if (!$veiculoId) {
    http_response_code(400);
    die('Veículo inválido.');
}

$stmt = $pdo->prepare('SELECT id, nome, tipo FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
$stmt->execute([':id' => $veiculoId, ':usuario_id' => $usuario['id']]);
$veiculo = $stmt->fetch();

if (!$veiculo) {
    http_response_code(404);
    die('Veículo não encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $acao = (string) ($_POST['acao'] ?? '');
    if ($acao === 'gerar') {
        $tokenNovo = criarOuRotacionarPassaporte($pdo, $usuario['id'], $veiculoId);
        if ($tokenNovo !== null) {
            // Guardado só até a próxima leitura (ver abaixo) — é a única vez
            // que o token em texto puro existe fora do link já compartilhado
            // pelo dono; o banco só tem o hash.
            $_SESSION['passaporte_token_novo'] = $tokenNovo;
            flashSet('sucesso', 'Link público gerado. Qualquer link anterior parou de funcionar.');
        }
    } elseif ($acao === 'revogar') {
        revogarPassaporte($pdo, $usuario['id'], $veiculoId);
        flashSet('sucesso', 'Link público revogado.');
    }

    header('Location: passaporte.php?veiculo_id=' . $veiculoId);
    exit;
}

$tokenParaExibir = $_SESSION['passaporte_token_novo'] ?? null;
unset($_SESSION['passaporte_token_novo']);

$ativo = passaporteAtivo($pdo, $usuario['id'], $veiculoId);

$tituloPagina = 'Passaporte do Veículo — PitStop BR';
$mostrarVoltar = true;
require __DIR__ . '/includes/header.php';
?>

<div class="px-1">
    <h6 class="text-muted mb-1 px-1">Passaporte do Veículo</h6>
    <p class="text-muted small px-1 mb-3">
        Gere um link público e somente leitura com o histórico completo de
        <strong><?= h($veiculo['nome']) ?></strong> — útil pra provar procedência na hora de vender.
        Quem receber o link não precisa de conta nem login, e só vê dados desse veículo.
    </p>

    <?php if ($tokenParaExibir !== null): ?>
    <div class="alert alert-success py-3 px-3 mb-3">
        <p class="small fw-semibold mb-2"><i class="bi bi-check-circle me-1"></i>Link gerado — copie agora:</p>
        <div class="input-group">
            <input type="text" class="form-control form-control-sm" id="linkPassaporte" readonly
                value="<?= h(baseUrl() . '/passaporte_publico.php?token=' . $tokenParaExibir) ?>">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="botaoCopiarPassaporte">
                <i class="bi bi-clipboard"></i> Copiar
            </button>
        </div>
        <p class="small text-muted mb-0 mt-2">Guarde esse link — por segurança, ele não fica visível de novo depois que você sair desta tela.</p>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <p class="mb-2">
                Status:
                <?php if ($ativo): ?>
                    <span class="badge bg-success">Link ativo</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Nenhum link ativo</span>
                <?php endif; ?>
            </p>

            <form method="post" action="passaporte.php" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="veiculo_id" value="<?= (int) $veiculoId ?>">
                <input type="hidden" name="acao" value="gerar">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-link-45deg me-1"></i><?= $ativo ? 'Gerar novo link (revoga o atual)' : 'Gerar link público' ?>
                </button>
            </form>

            <?php if ($ativo): ?>
            <form method="post" action="passaporte.php" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="veiculo_id" value="<?= (int) $veiculoId ?>">
                <input type="hidden" name="acao" value="revogar">
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-slash-circle me-1"></i>Revogar
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/js/passaporte.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
