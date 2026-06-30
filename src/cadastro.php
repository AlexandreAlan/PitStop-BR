<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

if (usuarioAtual() !== null) {
    header('Location: index.php');
    exit;
}

$erros = [];
$nome = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $nome           = trim((string) ($_POST['nome'] ?? ''));
    $email          = trim((string) ($_POST['email'] ?? ''));
    $senha          = (string) ($_POST['senha'] ?? '');
    $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');

    if ($senha !== $confirmarSenha) {
        $erros[] = 'As senhas não conferem.';
    } else {
        $resultado = registrarUsuario($pdo, $nome, $email, $senha);
        if ($resultado['ok']) {
            session_regenerate_id(true);
            $_SESSION['usuario_id']   = $resultado['id'];
            $_SESSION['usuario_nome'] = $resultado['nome'];

            flashSet('sucesso', 'Conta criada com sucesso. Bem-vindo(a)!');
            header('Location: index.php');
            exit;
        }
        $erros[] = $resultado['erro'];
    }
}

$tituloPagina = 'Criar Conta';
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

<form method="post" action="cadastro.php" class="card shadow-sm border-0" novalidate>
    <div class="card-body p-4">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="mb-3">
            <label class="form-label">Nome</label>
            <input type="text" name="nome" maxlength="100" class="form-control form-control-lg" value="<?= h($nome) ?>" required autofocus>
        </div>

        <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" maxlength="190" class="form-control form-control-lg" value="<?= h($email) ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Senha</label>
            <input type="password" name="senha" minlength="8" class="form-control form-control-lg" required>
            <div class="form-text">Mínimo de 8 caracteres.</div>
        </div>

        <div class="mb-4">
            <label class="form-label">Confirmar senha</label>
            <input type="password" name="confirmar_senha" minlength="8" class="form-control form-control-lg" required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
            <i class="bi bi-person-plus me-1"></i>Criar conta
        </button>

        <p class="text-center text-muted small mb-0">
            Já tem conta? <a href="login.php">Entrar</a>
        </p>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
