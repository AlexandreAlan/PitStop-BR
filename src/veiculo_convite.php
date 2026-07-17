<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    die('Convite inválido.');
}
$tokenHash = hash('sha256', $token);

$buscaConvite = $pdo->prepare(
    'SELECT vc.veiculo_id, v.nome AS veiculo_nome, u.nome AS convidado_por
     FROM veiculo_convites vc
     INNER JOIN veiculos v ON v.id = vc.veiculo_id
     INNER JOIN usuarios u ON u.id = vc.criado_por
     WHERE vc.token_hash = :token_hash AND vc.usado_em IS NULL AND vc.expira_em > NOW()'
);
$buscaConvite->execute([':token_hash' => $tokenHash]);
$convite = $buscaConvite->fetch();

if (!$convite) {
    $tituloPagina = 'Convite Inválido';
    $telaAuth = true;
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card shadow-sm border-0">
        <div class="card-body p-4 text-center">
            <p class="mb-3">Este convite é inválido, já foi usado ou expirou.</p>
            <a href="login.php" class="btn btn-outline-primary">Voltar pro login</a>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$usuarioLogado = usuarioAtual();

if ($usuarioLogado !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $veiculoId = aceitarConviteVeiculo($pdo, $token, $usuarioLogado['id']);
    if ($veiculoId !== null) {
        flashSet('sucesso', 'Você agora compartilha o veículo "' . $convite['veiculo_nome'] . '".');
        header('Location: veiculos.php');
        exit;
    }

    flashSet('erro', 'Este convite já foi usado ou expirou.');
    header('Location: veiculo_convite.php?token=' . $token);
    exit;
}

$tituloPagina = 'Convite de Veículo';
$telaAuth = true;
require __DIR__ . '/includes/header.php';
?>

<div class="card shadow-sm border-0">
    <div class="card-body p-4 text-center">
        <p class="mb-2"><strong><?= h($convite['convidado_por']) ?></strong> quer compartilhar o veículo</p>
        <p class="mb-3 fs-5 fw-semibold"><?= h($convite['veiculo_nome']) ?></p>

        <?php if ($usuarioLogado === null): ?>
        <p class="text-muted small mb-3">Entre na sua conta (ou crie uma) e depois abra este mesmo link de novo pra aceitar.</p>
        <a href="login.php" class="btn btn-primary w-100 mb-2">Entrar</a>
        <a href="cadastro.php" class="btn btn-outline-primary w-100">Criar conta</a>
        <?php else: ?>
        <p class="text-muted small mb-3">Você vai passar a ver e registrar abastecimentos, manutenções, despesas e lembretes deste veículo, com a conta <strong><?= h($usuarioLogado['nome']) ?></strong>.</p>
        <form method="post" action="veiculo_convite.php?token=<?= h($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-check-lg me-1"></i>Aceitar e compartilhar
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
