<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mailer.php';

const CONVITE_VALIDADE_DIAS = 7;

$usuario = exigirLogin();

$erros = [];
$emailConvite = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $emailConvite = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

    if (!filter_var($emailConvite, FILTER_VALIDATE_EMAIL) || mb_strlen($emailConvite) > 190) {
        $erros[] = 'E-mail inválido.';
    } else {
        $existeUsuario = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = :email');
        $existeUsuario->execute([':email' => $emailConvite]);
        if ($existeUsuario->fetchColumn()) {
            $erros[] = 'Já existe uma conta com esse e-mail.';
        }

        $convitePendente = $pdo->prepare(
            'SELECT 1 FROM convites WHERE email = :email AND usado_em IS NULL AND expira_em > NOW()'
        );
        $convitePendente->execute([':email' => $emailConvite]);
        if (!$erros && $convitePendente->fetchColumn()) {
            $erros[] = 'Já existe um convite pendente pra esse e-mail.';
        }
    }

    if (!$erros) {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiraEm  = (new DateTime())->modify('+' . CONVITE_VALIDADE_DIAS . ' days')->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO convites (email, token_hash, criado_por, expira_em) VALUES (:email, :token_hash, :criado_por, :expira_em)'
        );
        $stmt->execute([
            ':email'      => $emailConvite,
            ':token_hash' => $tokenHash,
            ':criado_por' => $usuario['id'],
            ':expira_em'  => $expiraEm,
        ]);

        $linkConvite = baseUrl() . '/convite.php?token=' . $token;

        $corpoHtml = '
<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#13151a; max-width:480px; margin:0 auto; line-height:1.6; font-size:15px;">
  <div style="background:linear-gradient(180deg,#23272f,#13151a); padding:24px 28px; border-radius:12px 12px 0 0;">
    <p style="margin:0; color:#ffffff; font-size:22px; font-weight:700;">Pit<span style="color:#ff6b35;">Stop</span> BR</p>
  </div>
  <div style="background:#ffffff; padding:28px; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 12px 12px;">
    <p>Olá!</p>
    <p><strong>' . h($usuario['nome']) . '</strong> convidou você para usar o PitStop BR, um app pra controlar abastecimentos e manutenções de veículos.</p>
    <p style="margin:28px 0;">
      <a href="' . h($linkConvite) . '" style="display:inline-block; background:#ff6b35; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:600; font-size:15px;">Aceitar convite</a>
    </p>
    <p style="color:#6b7280; font-size:13px;">Este link expira em ' . CONVITE_VALIDADE_DIAS . ' dias e só pode ser usado uma vez. Se você não esperava este convite, pode ignorar este e-mail.</p>
  </div>
</div>';

        $enviado = enviarEmail($emailConvite, 'Você foi convidado pro PitStop BR', $corpoHtml);
        if ($enviado) {
            flashSet('sucesso', 'Convite enviado para ' . $emailConvite . '.');
        } else {
            flashSet('erro', 'O convite foi gerado, mas o e-mail não pôde ser enviado agora. Tente novamente em instantes.');
        }
        header('Location: convidar.php');
        exit;
    }
}

$convitesStmt = $pdo->prepare(
    'SELECT email, criado_em, expira_em, usado_em FROM convites WHERE criado_por = :usuario_id ORDER BY criado_em DESC LIMIT 20'
);
$convitesStmt->execute([':usuario_id' => $usuario['id']]);
$convitesEnviados = $convitesStmt->fetchAll();

$tituloPagina = 'Convidar — PitStop BR';
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

<form method="post" action="convidar.php" class="px-1 mb-4" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

    <h6 class="text-muted mb-3 px-1">Convidar Pessoa</h6>

    <div class="mb-3">
        <label class="form-label">E-mail</label>
        <input type="email" name="email" maxlength="190" class="form-control form-control-lg" value="<?= h($emailConvite) ?>" placeholder="nome@exemplo.com" required>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100">
        <i class="bi bi-send me-1"></i>Enviar Convite
    </button>
</form>

<div class="px-1">
    <h6 class="text-muted mb-2 px-1">Convites Enviados</h6>
    <?php if (!$convitesEnviados): ?>
        <p class="text-center text-muted small py-3">Nenhum convite enviado ainda.</p>
    <?php else: ?>
        <?php foreach ($convitesEnviados as $c): ?>
        <?php
            $expirado = $c['usado_em'] === null && new DateTime($c['expira_em']) < new DateTime();
            if ($c['usado_em'] !== null) {
                $status = ['texto' => 'Aceito', 'classe' => 'bg-success'];
            } elseif ($expirado) {
                $status = ['texto' => 'Expirado', 'classe' => 'bg-secondary'];
            } else {
                $status = ['texto' => 'Pendente', 'classe' => 'bg-warning text-dark'];
            }
        ?>
        <div class="card shadow-sm mb-2">
            <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                <div class="small"><?= h($c['email']) ?></div>
                <span class="badge <?= $status['classe'] ?>"><?= $status['texto'] ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
