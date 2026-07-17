<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mailer.php';

const VEICULO_CONVITE_VALIDADE_DIAS = 7;
const VEICULO_CONVITE_LIMITE_POR_HORA = 20;

$usuario = exigirLogin();

$veiculoId = filter_input(INPUT_GET, 'veiculo_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'veiculo_id', FILTER_VALIDATE_INT);
if (!$veiculoId) {
    http_response_code(400);
    die('Veículo inválido.');
}

$erros = [];
$emailConvite = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();
    $acao = (string) ($_POST['acao'] ?? '');

    // "Sair" é a única ação que NÃO exige ser dono — qualquer colaborador
    // pode se remover por conta própria (ver removerCompartilhamentoVeiculo,
    // que autoriza dono OU o próprio usuário sendo removido).
    if ($acao === 'sair') {
        removerCompartilhamentoVeiculo($pdo, $usuario['id'], $veiculoId, $usuario['id']);
        flashSet('sucesso', 'Você saiu do compartilhamento deste veículo.');
        header('Location: veiculos.php');
        exit;
    }
}

// Todo o resto desta página (convidar, remover colaborador, ver a lista) é
// restrito ao DONO do veículo — responde 404 (não 403) pra quem não é,
// mesmo padrão já usado no painel administrativo (não revela que a rota
// existe pra outra conta).
$stmt = $pdo->prepare('SELECT id, nome FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
$stmt->execute([':id' => $veiculoId, ':usuario_id' => $usuario['id']]);
$veiculo = $stmt->fetch();

if (!$veiculo) {
    http_response_code(404);
    die('Veículo não encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'remover') {
        $usuarioIdRemovido = filter_input(INPUT_POST, 'usuario_id', FILTER_VALIDATE_INT);
        if ($usuarioIdRemovido) {
            removerCompartilhamentoVeiculo($pdo, $usuario['id'], $veiculoId, $usuarioIdRemovido);
            flashSet('sucesso', 'Colaborador removido.');
        }
        header('Location: veiculo_compartilhar.php?veiculo_id=' . $veiculoId);
        exit;
    }

    if ($acao === 'convidar') {
        $tentativasRecentes = $pdo->prepare(
            'SELECT COUNT(*) FROM convite_rate_limit WHERE usuario_id = :usuario_id AND criado_em > (NOW() - INTERVAL 1 HOUR)'
        );
        $tentativasRecentes->execute([':usuario_id' => $usuario['id']]);

        if ((int) $tentativasRecentes->fetchColumn() >= VEICULO_CONVITE_LIMITE_POR_HORA) {
            $erros[] = 'Muitos convites enviados na última hora. Tente novamente daqui a pouco.';
        } else {
            $emailConvite = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

            if (!filter_var($emailConvite, FILTER_VALIDATE_EMAIL) || mb_strlen($emailConvite) > 190) {
                $erros[] = 'E-mail inválido.';
            } else {
                $pdo->prepare('INSERT INTO convite_rate_limit (usuario_id) VALUES (:usuario_id)')
                    ->execute([':usuario_id' => $usuario['id']]);

                $token = criarConviteVeiculo($pdo, $usuario['id'], $veiculoId, $emailConvite);
                if ($token !== null) {
                    $linkConvite = baseUrl() . '/veiculo_convite.php?token=' . $token;
                    $corpoHtml = '
<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#13151a; max-width:480px; margin:0 auto; line-height:1.6; font-size:15px;">
  <div style="background:linear-gradient(180deg,#23272f,#13151a); padding:24px 28px; border-radius:12px 12px 0 0;">
    <p style="margin:0; color:#ffffff; font-size:22px; font-weight:700;">Pit<span style="color:#ff6b35;">Stop</span> BR</p>
  </div>
  <div style="background:#ffffff; padding:28px; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 12px 12px;">
    <p>Olá!</p>
    <p><strong>' . h($usuario['nome']) . '</strong> quer compartilhar o veículo <strong>' . h($veiculo['nome']) . '</strong> com você no PitStop BR — vocês dois vão poder registrar e ver abastecimentos, manutenções e despesas dele.</p>
    <p style="margin:28px 0;">
      <a href="' . h($linkConvite) . '" style="display:inline-block; background:#ff6b35; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:600; font-size:15px;">Aceitar e compartilhar</a>
    </p>
    <p style="color:#6b7280; font-size:13px;">Você precisa ter (ou criar) uma conta no PitStop BR com este e-mail. Este link expira em ' . VEICULO_CONVITE_VALIDADE_DIAS . ' dias e só pode ser usado uma vez. Se você não esperava este convite, pode ignorar este e-mail.</p>
  </div>
</div>';
                    enviarEmail($emailConvite, 'Convite pra compartilhar um veículo no PitStop BR', $corpoHtml);
                    flashSet('sucesso', 'Convite enviado para ' . $emailConvite . '.');
                    header('Location: veiculo_compartilhar.php?veiculo_id=' . $veiculoId);
                    exit;
                }
                $erros[] = 'Não foi possível enviar o convite.';
            }
        }
    }
}

$colaboradores = colaboradoresVeiculo($pdo, $veiculoId);

$convitesStmt = $pdo->prepare(
    'SELECT email, criado_em, expira_em, usado_em FROM veiculo_convites
     WHERE veiculo_id = :veiculo_id ORDER BY criado_em DESC LIMIT 20'
);
$convitesStmt->execute([':veiculo_id' => $veiculoId]);
$convitesEnviados = $convitesStmt->fetchAll();

$tituloPagina = 'Compartilhar Veículo — PitStop BR';
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

<div class="px-1">
    <h6 class="text-muted mb-1 px-1">Compartilhar <?= h($veiculo['nome']) ?></h6>
    <p class="text-muted small px-1 mb-3">
        Convide outra conta pra dividir o controle deste veículo com você (ex.: casal dividindo o mesmo carro).
        Quem aceitar passa a ver o histórico completo e pode registrar/editar abastecimentos, manutenções, despesas e lembretes dele.
        Só você, como dono, pode editar os dados do veículo, gerenciar o link público (passaporte) e remover colaboradores.
    </p>

    <?php if ($colaboradores): ?>
    <h6 class="text-muted mb-2 px-1">Colaboradores</h6>
    <?php foreach ($colaboradores as $c): ?>
    <div class="card shadow-sm mb-2">
        <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
            <div class="small">
                <div class="fw-semibold"><?= h($c['nome']) ?></div>
                <div class="text-muted"><?= h($c['email']) ?></div>
            </div>
            <form method="post" action="veiculo_compartilhar.php" class="form-remover-colaborador">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="veiculo_id" value="<?= (int) $veiculoId ?>">
                <input type="hidden" name="acao" value="remover">
                <input type="hidden" name="usuario_id" value="<?= (int) $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Remover">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <form method="post" action="veiculo_compartilhar.php" class="mb-4 mt-3" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="veiculo_id" value="<?= (int) $veiculoId ?>">
        <input type="hidden" name="acao" value="convidar">

        <h6 class="text-muted mb-3">Convidar Pessoa</h6>
        <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" maxlength="190" class="form-control form-control-lg" value="<?= h($emailConvite) ?>" placeholder="nome@exemplo.com" required>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-send me-1"></i>Enviar Convite
        </button>
    </form>

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

<script src="assets/js/veiculo_compartilhar.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
