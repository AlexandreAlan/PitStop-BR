<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mailer.php';

const REDEFINICAO_LIMITE_POR_HORA = 5;

if (usuarioAtual() !== null) {
    header('Location: index.php');
    exit;
}

$erros = [];
$email = '';
$enviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $ipHash = hash('sha256', clienteIp());
    $tentativasRecentes = $pdo->prepare(
        'SELECT COUNT(*) FROM redefinicao_rate_limit WHERE ip_hash = :ip AND criado_em > (NOW() - INTERVAL 1 HOUR)'
    );
    $tentativasRecentes->execute([':ip' => $ipHash]);

    if ((int) $tentativasRecentes->fetchColumn() >= REDEFINICAO_LIMITE_POR_HORA) {
        $erros[] = 'Muitas tentativas por aqui. Tente novamente daqui a pouco.';
    } else {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $pdo->prepare('INSERT INTO redefinicao_rate_limit (ip_hash) VALUES (:ip)')->execute([':ip' => $ipHash]);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'Informe um e-mail válido.';
        } else {
            $usuarioStmt = $pdo->prepare('SELECT id, nome FROM usuarios WHERE email = :email');
            $usuarioStmt->execute([':email' => $email]);
            $usuarioEncontrado = $usuarioStmt->fetch();

            if ($usuarioEncontrado) {
                $token = gerarTokenRedefinicaoSenha($pdo, (int) $usuarioEncontrado['id']);

                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
                $baseUrl = ($isHttps ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $linkRedefinicao = $baseUrl . '/redefinir_senha.php?token=' . $token;

                $corpoHtml = '
<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#13151a; max-width:480px; margin:0 auto; line-height:1.6; font-size:15px;">
  <div style="background:linear-gradient(180deg,#23272f,#13151a); padding:24px 28px; border-radius:12px 12px 0 0;">
    <p style="margin:0; color:#ffffff; font-size:22px; font-weight:700;">Pit<span style="color:#ff6b35;">Stop</span> BR</p>
  </div>
  <div style="background:#ffffff; padding:28px; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 12px 12px;">
    <p>Olá, ' . h($usuarioEncontrado['nome']) . '!</p>
    <p>Recebemos um pedido pra redefinir a senha da sua conta no PitStop BR. Clique no botão abaixo pra escolher uma nova senha:</p>
    <p style="margin:28px 0;">
      <a href="' . h($linkRedefinicao) . '" style="display:inline-block; background:#ff6b35; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:600; font-size:15px;">Redefinir senha</a>
    </p>
    <p style="color:#6b7280; font-size:13px;">Este link expira em ' . AUTH_RESET_VALIDADE_MINUTOS . ' minutos e só pode ser usado uma vez. Se você não pediu essa redefinição, pode ignorar este e-mail — sua senha continua a mesma.</p>
  </div>
</div>';

                enviarEmail($email, 'Redefinir sua senha — PitStop BR', $corpoHtml);
            }

            // Mesma resposta exista ou não a conta, pra não revelar quais e-mails têm cadastro.
            $enviado = true;
        }
    }
}

$tituloPagina = 'Esqueci Minha Senha';
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

<?php if ($enviado): ?>
<div class="card shadow-sm border-0">
    <div class="card-body p-4 text-center">
        <i class="bi bi-envelope-check text-success" style="font-size:2.5rem;"></i>
        <p class="mt-3 mb-3">Se esse e-mail estiver cadastrado, enviamos um link de redefinição. Confira sua caixa de entrada (e o spam).</p>
        <a href="login.php" class="btn btn-outline-primary">Voltar pro login</a>
    </div>
</div>
<?php else: ?>
<form method="post" action="esqueci_senha.php" class="card shadow-sm border-0" novalidate>
    <div class="card-body p-4">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <p class="text-muted small mb-3">Informe o e-mail da sua conta. Se ele estiver cadastrado, mandamos um
        link pra você escolher uma senha nova.</p>

        <div class="mb-4">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control form-control-lg" value="<?= h($email) ?>" autocomplete="email" required autofocus>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
            <i class="bi bi-send me-1"></i>Enviar link de redefinição
        </button>

        <p class="text-center text-muted small mb-0">
            <a href="login.php">Voltar pro login</a>
        </p>
    </div>
</form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
