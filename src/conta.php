<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$dadosStmt = $pdo->prepare('SELECT nome, email, criado_em, aceite_privacidade_em FROM usuarios WHERE id = :id');
$dadosStmt->execute([':id' => $usuario['id']]);
$dadosUsuario = $dadosStmt->fetch();

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_conta') {
    csrfVerificarOuFalhar();

    $senhaAtual = (string) ($_POST['senha_atual'] ?? '');

    $verifica = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = :id');
    $verifica->execute([':id' => $usuario['id']]);
    $senhaHash = (string) $verifica->fetchColumn();

    if (!password_verify($senhaAtual, $senhaHash)) {
        $erros[] = 'Senha incorreta. A conta não foi excluída.';
    } else {
        $excluir = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
        $excluir->execute([':id' => $usuario['id']]);

        unset($_SESSION['usuario_id'], $_SESSION['usuario_nome']);
        session_regenerate_id(true);
        flashSet('sucesso', 'Sua conta e todos os seus dados foram excluídos.');
        header('Location: login.php');
        exit;
    }
}

$tituloPagina = 'Minha Conta — PitStop BR';
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

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <h6 class="text-muted mb-3">Meus Dados</h6>
        <p class="mb-1"><strong>Nome:</strong> <?= h($dadosUsuario['nome']) ?></p>
        <p class="mb-1"><strong>E-mail:</strong> <?= h($dadosUsuario['email']) ?></p>
        <p class="mb-1"><strong>Conta criada em:</strong> <?= h((new DateTime($dadosUsuario['criado_em']))->format('d/m/Y')) ?></p>
        <p class="mb-0 small text-muted">
            <a href="privacidade.php">Ver Política de Privacidade</a>
        </p>
    </div>
</div>

<div class="card shadow-sm border-0 border-danger-subtle">
    <div class="card-body p-4">
        <h6 class="text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Excluir Conta</h6>
        <p class="small text-muted">Isso apaga permanentemente sua conta, seus veículos e todos os
        registros de abastecimento/manutenção. Não pode ser desfeito.</p>

        <form method="post" action="conta.php" onsubmit="return confirm('Excluir sua conta e todos os dados? Essa ação não pode ser desfeita.');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="acao" value="excluir_conta">

            <div class="mb-3">
                <label class="form-label">Confirme sua senha</label>
                <input type="password" name="senha_atual" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-outline-danger w-100">
                <i class="bi bi-trash me-1"></i>Excluir minha conta e meus dados
            </button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
