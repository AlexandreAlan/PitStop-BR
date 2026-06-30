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
    die('Convite inválido.');
}
$tokenHash = hash('sha256', $token);

$buscaConvite = $pdo->prepare(
    'SELECT id, email FROM convites WHERE token_hash = :token_hash AND usado_em IS NULL AND expira_em > NOW()'
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

$erros = [];
$nome = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $nome           = trim((string) ($_POST['nome'] ?? ''));
    $senha          = (string) ($_POST['senha'] ?? '');
    $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');

    if ($senha !== $confirmarSenha) {
        $erros[] = 'As senhas não conferem.';
    }

    if (!$erros) {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                'SELECT id, email FROM convites WHERE id = :id AND usado_em IS NULL AND expira_em > NOW() FOR UPDATE'
            );
            $lock->execute([':id' => $convite['id']]);
            $conviteTravado = $lock->fetch();

            if (!$conviteTravado) {
                $pdo->rollBack();
                $erros[] = 'Este convite já foi usado ou expirou.';
            } else {
                $resultado = registrarUsuario($pdo, $nome, $conviteTravado['email'], $senha);
                if (!$resultado['ok']) {
                    $pdo->rollBack();
                    $erros[] = $resultado['erro'];
                } else {
                    $marcarUsado = $pdo->prepare('UPDATE convites SET usado_em = NOW() WHERE id = :id');
                    $marcarUsado->execute([':id' => $conviteTravado['id']]);
                    $pdo->commit();

                    session_regenerate_id(true);
                    $_SESSION['usuario_id']   = $resultado['id'];
                    $_SESSION['usuario_nome'] = $resultado['nome'];

                    flashSet('sucesso', 'Conta criada com sucesso. Bem-vindo(a)!');
                    header('Location: index.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

$tituloPagina = 'Aceitar Convite';
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

<form method="post" action="convite.php?token=<?= h($token) ?>" class="card shadow-sm border-0" novalidate>
    <div class="card-body p-4">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input type="email" class="form-control form-control-lg" value="<?= h($convite['email']) ?>" disabled>
        </div>

        <div class="mb-3">
            <label class="form-label">Nome</label>
            <input type="text" name="nome" maxlength="100" class="form-control form-control-lg" value="<?= h($nome) ?>" required autofocus>
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

        <button type="submit" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-check-lg me-1"></i>Criar conta
        </button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
