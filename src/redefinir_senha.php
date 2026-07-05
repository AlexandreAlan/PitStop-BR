<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

if (usuarioAtual() !== null) {
    header('Location: index.php');
    exit;
}

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    die('Link inválido.');
}
$tokenHash = hash('sha256', $token);

$buscaToken = $pdo->prepare(
    'SELECT id FROM redefinicoes_senha WHERE token_hash = :token_hash AND usado_em IS NULL AND expira_em > NOW()'
);
$buscaToken->execute([':token_hash' => $tokenHash]);
$tokenValido = $buscaToken->fetch();

if (!$tokenValido) {
    $tituloPagina = 'Link Inválido';
    $telaAuth = true;
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card shadow-sm border-0">
        <div class="card-body p-4 text-center">
            <p class="mb-3">Este link é inválido, já foi usado ou expirou.</p>
            <a href="esqueci_senha.php" class="btn btn-outline-primary">Pedir um novo link</a>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $senha          = (string) ($_POST['senha'] ?? '');
    $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');

    if ($senha !== $confirmarSenha) {
        $erros[] = 'As senhas não conferem.';
    } else {
        $resultado = redefinirSenhaComToken($pdo, $token, $senha);
        if ($resultado['ok']) {
            flashSet('sucesso', 'Senha redefinida com sucesso. Entre com a nova senha.');
            header('Location: login.php');
            exit;
        }
        $erros[] = $resultado['erro'];
    }
}

$tituloPagina = 'Redefinir Senha';
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

<form method="post" action="redefinir_senha.php?token=<?= h($token) ?>" class="card shadow-sm border-0" novalidate>
    <div class="card-body p-4">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <div class="mb-3">
            <label class="form-label">Nova senha</label>
            <div class="input-group">
                <input type="password" name="senha" id="campoNovaSenha" minlength="8" class="form-control form-control-lg" autocomplete="new-password" required>
                <button type="button" class="btn btn-outline-secondary campo-senha-toggle" data-alvo="campoNovaSenha" tabindex="-1" aria-label="Mostrar senha">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            <div class="form-text">Mínimo de 8 caracteres.</div>
        </div>

        <div class="mb-4">
            <label class="form-label">Confirmar nova senha</label>
            <div class="input-group">
                <input type="password" name="confirmar_senha" id="campoConfirmarSenha" minlength="8" class="form-control form-control-lg" autocomplete="new-password" required>
                <button type="button" class="btn btn-outline-secondary campo-senha-toggle" data-alvo="campoConfirmarSenha" tabindex="-1" aria-label="Mostrar senha">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-check-lg me-1"></i>Salvar nova senha
        </button>
    </div>
</form>

<script src="assets/js/auth.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
