<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mailer.php';

const CONVITE_VALIDADE_DIAS = 7;
const CONVITE_LIMITE_POR_HORA = 20;

$usuario = exigirLogin();

$erros = [];
$emailConvite = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $tentativasRecentes = $pdo->prepare(
        'SELECT COUNT(*) FROM convite_rate_limit WHERE usuario_id = :usuario_id AND criado_em > (NOW() - INTERVAL 1 HOUR)'
    );
    $tentativasRecentes->execute([':usuario_id' => $usuario['id']]);

    if ((int) $tentativasRecentes->fetchColumn() >= CONVITE_LIMITE_POR_HORA) {
        $erros[] = 'Muitos convites enviados na última hora. Tente novamente daqui a pouco.';
    } else {
        $emailConvite = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

        if (!filter_var($emailConvite, FILTER_VALIDATE_EMAIL) || mb_strlen($emailConvite) > 190) {
            $erros[] = 'E-mail inválido.';
        } else {
            $pdo->prepare('INSERT INTO convite_rate_limit (usuario_id) VALUES (:usuario_id)')
                ->execute([':usuario_id' => $usuario['id']]);

            // Não revela se o e-mail já tem conta ou já tem convite pendente
            // (mesmo padrão anti-enumeração de cadastro.php/esqueci_senha.php)
            // — a mesma tela de sucesso aparece nos três casos abaixo; só o
            // conteúdo do e-mail muda de acordo com o estado real.
            $existeUsuario = $pdo->prepare('SELECT nome FROM usuarios WHERE email = :email');
            $existeUsuario->execute([':email' => $emailConvite]);
            $usuarioExistente = $existeUsuario->fetch();

            $convitePendente = $pdo->prepare(
                'SELECT 1 FROM convites WHERE email = :email AND usado_em IS NULL AND expira_em > NOW()'
            );
            $convitePendente->execute([':email' => $emailConvite]);
            $temConvitePendente = (bool) $convitePendente->fetchColumn();

            if ($usuarioExistente) {
                $corpoHtml = '
<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#13151a; max-width:480px; margin:0 auto; line-height:1.6; font-size:15px;">
  <div style="background:linear-gradient(180deg,#23272f,#13151a); padding:24px 28px; border-radius:12px 12px 0 0;">
    <p style="margin:0; color:#ffffff; font-size:22px; font-weight:700;">Pit<span style="color:#ff6b35;">Stop</span> BR</p>
  </div>
  <div style="background:#ffffff; padding:28px; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 12px 12px;">
    <p>Olá, ' . h($usuarioExistente['nome']) . '!</p>
    <p>Alguém tentou convidar você para o PitStop BR usando este e-mail, que já tem conta por aqui.</p>
    <p>Se foi você e só esqueceu que já tinha conta, pode entrar direto pela tela de login. Se não foi você, pode ignorar este e-mail — nada mudou na sua conta.</p>
  </div>
</div>';
                enviarEmail($emailConvite, 'Convite pro PitStop BR', $corpoHtml);
            } elseif (!$temConvitePendente) {
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
                enviarEmail($emailConvite, 'Você foi convidado pro PitStop BR', $corpoHtml);
            }
            // $temConvitePendente sem $usuarioExistente: já tem convite
            // válido rolando pra esse e-mail — não manda de novo (evita
            // spam), mas também não avisa isso pro requisitante.

            flashSet('sucesso', 'Convite enviado para ' . $emailConvite . '.');
            header('Location: convidar.php');
            exit;
        }
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
