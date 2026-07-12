<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$dadosStmt = $pdo->prepare('SELECT nome, email, criado_em, aceite_privacidade_em, meta_mensal FROM usuarios WHERE id = :id');
$dadosStmt->execute([':id' => $usuario['id']]);
$dadosUsuario = $dadosStmt->fetch();

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_meta') {
    csrfVerificarOuFalhar();

    $metaBruta = trim((string) ($_POST['meta_mensal'] ?? ''));
    $metaNormalizada = str_replace(',', '.', $metaBruta);

    if ($metaBruta === '') {
        $atualiza = $pdo->prepare('UPDATE usuarios SET meta_mensal = NULL WHERE id = :id');
        $atualiza->execute([':id' => $usuario['id']]);
        $dadosUsuario['meta_mensal'] = null;
        flashSet('sucesso', 'Meta de gasto mensal removida.');
        header('Location: conta.php');
        exit;
    } elseif (!is_numeric($metaNormalizada) || (float) $metaNormalizada <= 0) {
        $erros[] = 'Informe um valor válido (maior que zero) para a meta mensal.';
    } else {
        $metaValor = round((float) $metaNormalizada, 2);
        $atualiza = $pdo->prepare('UPDATE usuarios SET meta_mensal = :meta WHERE id = :id');
        $atualiza->execute([':meta' => $metaValor, ':id' => $usuario['id']]);
        flashSet('sucesso', 'Meta de gasto mensal atualizada.');
        header('Location: conta.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_conta') {
    csrfVerificarOuFalhar();

    $senhaAtual = (string) ($_POST['senha_atual'] ?? '');

    $verifica = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = :id');
    $verifica->execute([':id' => $usuario['id']]);
    $senhaHash = (string) $verifica->fetchColumn();

    if (!password_verify($senhaAtual, $senhaHash)) {
        $erros[] = 'Senha incorreta. A conta não foi excluída.';
    } else {
        $excluir = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
        $excluir->execute([':id' => $usuario['id']]);

        unset($_SESSION['usuario_id'], $_SESSION['usuario_nome']);
        session_regenerate_id(true);
        flashSet('sucesso', 'Sua conta e todos os seus dados foram excluídos.');
        header('Location: login.php');
        exit;
    }
}

$tituloPagina = 'Minha Conta — PitStop BR';
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

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <h6 class="text-muted mb-3">Meus Dados</h6>
        <p class="mb-1"><strong>Nome:</strong> <?= h($dadosUsuario['nome']) ?></p>
        <p class="mb-1"><strong>E-mail:</strong> <?= h($dadosUsuario['email']) ?></p>
        <p class="mb-1"><strong>Conta criada em:</strong> <?= h((new DateTime($dadosUsuario['criado_em']))->format('d/m/Y')) ?></p>
        <p class="mb-0 small text-muted">
            <a href="privacidade.php">Ver Política de Privacidade</a>
        </p>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <h6 class="text-muted mb-1"><i class="bi bi-download me-1"></i>Meus Dados</h6>
        <p class="small text-muted mb-3">Baixe uma cópia de tudo que você cadastrou (perfil, veículos,
        abastecimentos, manutenções, despesas e lembretes) em um arquivo estruturado — direito de
        portabilidade garantido pela LGPD (Art. 18, V).</p>
        <a href="api/exportar_dados.php" class="btn btn-outline-primary w-100">
            <i class="bi bi-download me-1"></i>Baixar meus dados (JSON)
        </a>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <h6 class="text-muted mb-1"><i class="bi bi-bullseye me-1"></i>Meta de Gasto Mensal</h6>
        <p class="small text-muted mb-3">Defina um teto de gasto por mês (combustível, manutenção e
        despesas somados). O painel principal mostra o progresso com uma barra colorida.</p>

        <form method="post" action="conta.php" class="d-flex gap-2 align-items-start">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="acao" value="salvar_meta">

            <div class="flex-grow-1">
                <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="text" inputmode="decimal" name="meta_mensal" class="form-control"
                        placeholder="Ex: 600,00"
                        value="<?= $dadosUsuario['meta_mensal'] !== null ? h(number_format((float) $dadosUsuario['meta_mensal'], 2, ',', '')) : '' ?>">
                </div>
                <div class="form-text">Deixe em branco e salve pra remover a meta.</div>
            </div>
            <button type="submit" class="btn btn-primary">Salvar</button>
        </form>
    </div>
</div>

<?php $vapidPublicKey = (string) (getenv('VAPID_PUBLIC_KEY') ?: ''); ?>
<?php if ($vapidPublicKey !== ''): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <h6 class="text-muted mb-1"><i class="bi bi-bell me-1"></i>Notificações</h6>
        <p class="small text-muted mb-3">Receba um aviso no celular quando um lembrete de manutenção
        vencer ou estiver próximo — mesmo com o app fechado.</p>

        <button type="button" id="btnPushToggle" class="btn btn-primary w-100"
            data-vapid="<?= h($vapidPublicKey) ?>" data-csrf="<?= h(csrfToken()) ?>">
            Ativar notificações de lembretes
        </button>
    </div>
</div>
<script src="assets/js/push.js"></script>
<?php endif; ?>

<div class="card shadow-sm border-0 border-danger-subtle">
    <div class="card-body p-4">
        <h6 class="text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Excluir Conta</h6>
        <p class="small text-muted">Isso apaga permanentemente sua conta, seus veículos e todos os
        registros de abastecimento/manutenção. Não pode ser desfeito.</p>

        <form method="post" action="conta.php" onsubmit="return confirm('Excluir sua conta e todos os dados? Essa ação não pode ser desfeita.');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="acao" value="excluir_conta">

            <div class="mb-3">
                <label class="form-label">Confirme sua senha</label>
                <input type="password" name="senha_atual" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-outline-danger w-100">
                <i class="bi bi-trash me-1"></i>Excluir minha conta e meus dados
            </button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
