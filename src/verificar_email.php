<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mailer.php';

const REENVIO_INTERVALO_SEGUNDOS = 30;

if (usuarioAtual() !== null) {
    header('Location: index.php');
    exit;
}

$usuarioId = (int) ($_SESSION['verificacao_pendente_id'] ?? 0);
if ($usuarioId === 0) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, nome, role, email, email_verificado_em FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $usuarioId]);
$usuario = $stmt->fetch();

if (!$usuario) {
    unset($_SESSION['verificacao_pendente_id']);
    header('Location: login.php');
    exit;
}

if ($usuario['email_verificado_em'] !== null) {
    unset($_SESSION['verificacao_pendente_id']);
    header('Location: login.php');
    exit;
}

$erros = [];
$sucessoReenvio = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    if (isset($_POST['reenviar'])) {
        $ultimoCodigo = $pdo->prepare('SELECT criado_em FROM verificacoes_email WHERE usuario_id = :id ORDER BY criado_em DESC LIMIT 1');
        $ultimoCodigo->execute([':id' => $usuarioId]);
        $criadoEm = $ultimoCodigo->fetchColumn();

        if ($criadoEm && (time() - strtotime((string) $criadoEm)) < REENVIO_INTERVALO_SEGUNDOS) {
            $erros[] = 'Aguarde alguns segundos antes de pedir um novo código.';
        } else {
            $codigo = gerarCodigoVerificacao($pdo, $usuarioId);
            $corpoHtml = '
<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#13151a; max-width:480px; margin:0 auto; line-height:1.6; font-size:15px;">
  <div style="background:linear-gradient(180deg,#23272f,#13151a); padding:24px 28px; border-radius:12px 12px 0 0;">
    <p style="margin:0; color:#ffffff; font-size:22px; font-weight:700;">Pit<span style="color:#ff6b35;">Stop</span> BR</p>
  </div>
  <div style="background:#ffffff; padding:28px; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 12px 12px;">
    <p>Olá, ' . h($usuario['nome']) . '!</p>
    <p>Seu novo código de confirmação:</p>
    <p style="margin:28px 0; text-align:center; font-size:32px; font-weight:700; letter-spacing:6px; color:#ff6b35;">' . h($codigo) . '</p>
    <p style="color:#6b7280; font-size:13px;">Esse código vale por ' . AUTH_CODIGO_VALIDADE_MINUTOS . ' minutos.</p>
  </div>
</div>';
            enviarEmail($usuario['email'], 'Novo código de confirmação — PitStop BR', $corpoHtml);
            $sucessoReenvio = true;
        }
    } else {
        $codigoDigitado = trim((string) ($_POST['codigo'] ?? ''));
        $resultado = verificarCodigoEmail($pdo, $usuarioId, $codigoDigitado);

        if ($resultado['ok']) {
            unset($_SESSION['verificacao_pendente_id']);
            session_regenerate_id(true);
            $_SESSION['usuario_id']   = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_role'] = $usuario['role'];

            flashSet('sucesso', 'E-mail confirmado. Bem-vindo(a) ao PitStop BR!');
            header('Location: index.php');
            exit;
        }
        $erros[] = $resultado['erro'];
    }
}

$tituloPagina = 'Confirmar E-mail';
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

<?php if ($sucessoReenvio): ?>
<div class="alert alert-success py-2">Enviamos um novo código pro seu e-mail.</div>
<?php endif; ?>

<form method="post" action="verificar_email.php" class="card shadow-sm border-0" novalidate>
    <div class="card-body p-4">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <p class="mb-3">Mandamos um código de 6 dígitos pra <strong><?= h($usuario['email']) ?></strong>. Digite abaixo pra confirmar sua conta.</p>

        <div class="mb-4">
            <label class="form-label">Código de confirmação</label>
            <input type="text" name="codigo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" class="form-control form-control-lg text-center" style="letter-spacing:.5em; font-size:1.5rem;" required autofocus>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
            <i class="bi bi-check-circle me-1"></i>Confirmar
        </button>
    </div>
</form>

<form method="post" action="verificar_email.php" class="mt-3">
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="reenviar" value="1">
    <button type="submit" class="btn btn-link btn-sm text-white-50 w-100">Não recebeu? Reenviar código</button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
