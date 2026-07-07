<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mailer.php';

const CADASTRO_LIMITE_POR_HORA = 5;

if (usuarioAtual() !== null) {
    header('Location: index.php');
    exit;
}

$erros = [];
$nome = '';
$email = '';
$cadastroConcluido = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $ipHash = hash('sha256', clienteIp());
    $tentativasRecentes = $pdo->prepare(
        'SELECT COUNT(*) FROM cadastro_rate_limit WHERE ip_hash = :ip AND criado_em > (NOW() - INTERVAL 1 HOUR)'
    );
    $tentativasRecentes->execute([':ip' => $ipHash]);

    if ((int) $tentativasRecentes->fetchColumn() >= CADASTRO_LIMITE_POR_HORA) {
        $erros[] = 'Muitas tentativas de cadastro por aqui. Tente novamente daqui a pouco.';
    } else {
        $nome           = trim((string) ($_POST['nome'] ?? ''));
        $email          = trim((string) ($_POST['email'] ?? ''));
        $senha          = (string) ($_POST['senha'] ?? '');
        $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');

        $aceitouPrivacidade = !empty($_POST['aceite_privacidade']);

        $pdo->prepare('INSERT INTO cadastro_rate_limit (ip_hash) VALUES (:ip)')->execute([':ip' => $ipHash]);

        if ($senha !== $confirmarSenha) {
            $erros[] = 'As senhas não conferem.';
        } elseif (!$aceitouPrivacidade) {
            $erros[] = 'É necessário aceitar a Política de Privacidade pra criar a conta.';
        } else {
            $resultado = registrarUsuario($pdo, $nome, $email, $senha, true);
            if ($resultado['ok']) {
                $codigo = gerarCodigoVerificacao($pdo, $resultado['id']);

                $corpoHtml = '
<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#13151a; max-width:480px; margin:0 auto; line-height:1.6; font-size:15px;">
  <div style="background:linear-gradient(180deg,#23272f,#13151a); padding:24px 28px; border-radius:12px 12px 0 0;">
    <p style="margin:0; color:#ffffff; font-size:22px; font-weight:700;">Pit<span style="color:#ff6b35;">Stop</span> BR</p>
  </div>
  <div style="background:#ffffff; padding:28px; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 12px 12px;">
    <p>Olá, ' . h($resultado['nome']) . '!</p>
    <p>Use o código abaixo pra confirmar seu e-mail e ativar sua conta no PitStop BR:</p>
    <p style="margin:28px 0; text-align:center; font-size:32px; font-weight:700; letter-spacing:6px; color:#ff6b35;">' . h($codigo) . '</p>
    <p style="color:#6b7280; font-size:13px;">Esse código vale por ' . AUTH_CODIGO_VALIDADE_MINUTOS . ' minutos. Se você não pediu esse cadastro, pode ignorar este e-mail.</p>
  </div>
</div>';
                enviarEmail($email, 'Seu código de confirmação — PitStop BR', $corpoHtml);

                // Sem redirect (Location) aqui: um 302 imediato só nesse
                // caminho seria, sozinho, um jeito ainda mais óbvio que
                // timing de distinguir "e-mail novo" de "e-mail já existe"
                // (o outro ramo, abaixo, não redireciona). Os dois ramos
                // respondem 200 com a mesma tela; quem se cadastrou de
                // verdade segue pro código pelo link "Confirmar e-mail".
                $_SESSION['verificacao_pendente_id'] = $resultado['id'];
                $cadastroConcluido = true;
            } elseif ($resultado['erro'] === 'email_existente') {
                // Não revela que a conta já existe: sem código, sem login
                // automático — só avisa o dono de verdade daquele e-mail, e
                // mostra pro requisitante a MESMA tela de sucesso de um
                // cadastro novo (ver $cadastroConcluido abaixo).
                $corpoHtml = '
<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#13151a; max-width:480px; margin:0 auto; line-height:1.6; font-size:15px;">
  <div style="background:linear-gradient(180deg,#23272f,#13151a); padding:24px 28px; border-radius:12px 12px 0 0;">
    <p style="margin:0; color:#ffffff; font-size:22px; font-weight:700;">Pit<span style="color:#ff6b35;">Stop</span> BR</p>
  </div>
  <div style="background:#ffffff; padding:28px; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 12px 12px;">
    <p>Olá!</p>
    <p>Alguém tentou criar uma conta no PitStop BR usando este e-mail, que já está cadastrado.</p>
    <p>Se foi você e esqueceu sua senha, use a opção <strong>"Esqueci minha senha"</strong> na tela de login pra recuperar o acesso. Se não foi você, pode ignorar este e-mail — nada mudou na sua conta.</p>
  </div>
</div>';
                enviarEmail($email, 'Tentativa de cadastro — PitStop BR', $corpoHtml);
                $cadastroConcluido = true;
            } else {
                $erros[] = $resultado['erro'];
            }
        }
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

<?php if ($cadastroConcluido): ?>
<div class="card shadow-sm border-0">
    <div class="card-body p-4 text-center">
        <i class="bi bi-envelope-check text-success" style="font-size:2.5rem;"></i>
        <p class="mt-3 mb-3">Quase lá! Mandamos as instruções de confirmação pro e-mail informado. Confira sua caixa de entrada (e o spam).</p>
        <a href="verificar_email.php" class="btn btn-primary mb-2 w-100">Confirmar e-mail</a>
        <a href="login.php" class="btn btn-outline-secondary w-100">Voltar pro login</a>
    </div>
</div>
<?php else: ?>
<form method="post" action="cadastro.php" class="card shadow-sm border-0" novalidate>
    <div class="card-body p-4">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="mb-3">
            <label class="form-label">Nome completo</label>
            <input type="text" name="nome" maxlength="100" class="form-control form-control-lg" value="<?= h($nome) ?>" placeholder="Nome e sobrenome" autocomplete="name" required autofocus>
        </div>

        <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" maxlength="190" class="form-control form-control-lg" value="<?= h($email) ?>" autocomplete="email" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Senha</label>
            <div class="input-group">
                <input type="password" name="senha" id="campoSenhaCadastro" minlength="8" class="form-control form-control-lg" autocomplete="new-password" required>
                <button type="button" class="btn btn-outline-secondary campo-senha-toggle" data-alvo="campoSenhaCadastro" tabindex="-1" aria-label="Mostrar senha">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            <div class="form-text">Mínimo de 8 caracteres.</div>
        </div>

        <div class="mb-3">
            <label class="form-label">Confirmar senha</label>
            <div class="input-group">
                <input type="password" name="confirmar_senha" id="campoConfirmarSenhaCadastro" minlength="8" class="form-control form-control-lg" autocomplete="new-password" required>
                <button type="button" class="btn btn-outline-secondary campo-senha-toggle" data-alvo="campoConfirmarSenhaCadastro" tabindex="-1" aria-label="Mostrar senha">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <div class="mb-4 form-check">
            <input type="checkbox" name="aceite_privacidade" id="aceitePrivacidade" class="form-check-input" required>
            <label class="form-check-label small" for="aceitePrivacidade">
                Li e aceito a <a href="privacidade.php" target="_blank" rel="noopener">Política de Privacidade</a>.
            </label>
        </div>

        <p class="text-muted small mb-3">
            <i class="bi bi-envelope-check me-1"></i>Vamos mandar um código pro seu e-mail pra confirmar a conta.
        </p>

        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
            <i class="bi bi-person-plus me-1"></i>Criar conta
        </button>

        <p class="text-center text-muted small mb-0">
            Já tem conta? <a href="login.php">Entrar</a>
        </p>
    </div>
</form>
<?php endif; ?>

<script src="assets/js/auth.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
