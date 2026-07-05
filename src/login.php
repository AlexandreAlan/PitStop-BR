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
        if (!empty($resultado['precisaVerificar'])) {
            header('Location: verificar_email.php');
            exit;
        }
        header('Location: index.php');
        exit;
    }

    $erros[] = $resultado['erro'];
}

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
            <input type="email" name="email" class="form-control form-control-lg" value="<?= h($email) ?>" autocomplete="email" required autofocus>
        </div>

        <div class="mb-2">
            <label class="form-label">Senha</label>
            <div class="input-group">
                <input type="password" name="senha" id="campoSenhaLogin" class="form-control form-control-lg" autocomplete="current-password" required>
                <button type="button" class="btn btn-outline-secondary campo-senha-toggle" data-alvo="campoSenhaLogin" tabindex="-1" aria-label="Mostrar senha">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <div class="mb-3 d-flex justify-content-between align-items-center">
            <div class="form-check mb-0">
                <input type="checkbox" id="lembrarEmail" class="form-check-input">
                <label class="form-check-label small" for="lembrarEmail">Lembrar meu e-mail</label>
            </div>
            <a href="esqueci_senha.php" class="small">Esqueceu a senha?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
            <i class="bi bi-box-arrow-in-right me-1"></i>Entrar
        </button>

        <p class="text-center text-muted small mb-0">
            Não tem conta? <a href="cadastro.php">Cadastre-se</a>
        </p>
    </div>
</form>

<script src="assets/js/auth.js"></script>

<p class="text-center mt-3 mb-0">
    <a href="instalar.php" class="small text-white-50"><i class="bi bi-phone me-1"></i>Instalar o app no celular</a>
</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
