<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

if (usuarioAtual() !== null) {
    header('Location: index.php');
    exit;
}

$erros = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $email = trim((string) ($_POST['email'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');

    $resultado = loginUsuario($pdo, $email, $senha);
    if ($resultado['ok']) {
        header('Location: index.php');
        exit;
    }

    $erros[] = $resultado['erro'];
}

$existeAlgumUsuario = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;

$tituloPagina = 'Entrar';
$telaAuth = true;
require __DIR__ . '/includes/header.php';
?>

<?php if ($erros): ?>
<div class="alert alert-danger py-2">
    <ul class="mb-0 ps-3 small">
        <?php foreach ($erros as $erro): ?><li><?= h($erro) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="login.php" class="card shadow-sm border-0" novalidate>
    <div class="card-body p-4">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control form-control-lg" value="<?= h($email) ?>" required autofocus>
        </div>

        <div class="mb-4">
            <label class="form-label">Senha</label>
            <input type="password" name="senha" class="form-control form-control-lg" required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
            <i class="bi bi-box-arrow-in-right me-1"></i>Entrar
        </button>

        <p class="text-center text-muted small mb-0">
            <?php if ($existeAlgumUsuario): ?>
                Não tem conta? Peça um convite a alguém que já usa o PitStop BR.
            <?php else: ?>
                Não tem conta? <a href="cadastro.php">Cadastre-se</a>
            <?php endif; ?>
        </p>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
