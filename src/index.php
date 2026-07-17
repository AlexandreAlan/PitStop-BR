<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

// Controla, em header.php, onde o aviso de atualização (#aviso-atualizacao)
// pode aparecer — só o dashboard, nunca em telas internas (Adicionar,
// Relatórios etc.). O canal de push (lembretes/alertas) é o outro lugar
// permitido, já existe à parte e não depende dessa flag.
$telaPrincipal = true;

$veiculos = veiculosAcessiveis($pdo, $usuario['id']);

$veiculoIdFiltro = filter_input(INPUT_GET, 'veiculo_id', FILTER_VALIDATE_INT) ?: null;

$ultimaMedia = calcularUltimaMedia($pdo, $usuario['id'], $veiculoIdFiltro);
$conquistas = calcularConquistas($pdo, $usuario['id']);

// Só faz sentido calcular (autonomia, estimativa) quando dá pra saber de
// qual veículo é — um veículo específico filtrado, ou o único que o usuário
// tem (sem essa certeza, misturar veículos diferentes não diz nada).
$veiculoParaAutonomia = $veiculoIdFiltro ?? (count($veiculos) === 1 ? (int) $veiculos[0]['id'] : null);

// "Sem dados" sozinho é ambíguo: parece bug quando na verdade só falta
// fechar o trecho (ver calcularTrechosConsumo() — um abastecimento parcial
// não fecha sozinho, fica acumulado até o próximo tanque cheio). Distingue
// esse caso (2+ abastecimentos já registrados, mas nenhum trecho fechado)
// do caso de fato "sem histórico" (0 ou 1 abastecimento) — e, no primeiro
// caso, oferece uma estimativa best-effort (calcularUltimaMediaEstimativa,
// SEM exigir tanque cheio), sempre marcada como não confirmada na tela.
$aguardandoTanqueCheio = false;
$mediaEstimativa = null;
if ($ultimaMedia === null) {
    $totalAbastecimentosStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM registros r
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE ' . condicaoAcessoVeiculo('v') . ' AND r.tipo_registro = "Abastecimento" AND r.litros IS NOT NULL'
        . ($veiculoIdFiltro !== null ? ' AND r.veiculo_id = :veiculo_id' : '')
    );
    bindAcessoVeiculo($totalAbastecimentosStmt, $usuario['id']);
    if ($veiculoIdFiltro !== null) {
        $totalAbastecimentosStmt->bindValue(':veiculo_id', $veiculoIdFiltro, PDO::PARAM_INT);
    }
    $totalAbastecimentosStmt->execute();
    $aguardandoTanqueCheio = (int) $totalAbastecimentosStmt->fetchColumn() >= 2;

    if ($aguardandoTanqueCheio && $veiculoParaAutonomia !== null) {
        $mediaEstimativa = calcularUltimaMediaEstimativa($pdo, $usuario['id'], $veiculoParaAutonomia);
    }
}

$autonomiaKm = null;
if ($veiculoParaAutonomia !== null && $ultimaMedia !== null) {
    $tanqueStmt = $pdo->prepare('SELECT tanque_litros FROM veiculos v WHERE v.id = :id AND ' . condicaoAcessoVeiculo('v'));
    $tanqueStmt->bindValue(':id', $veiculoParaAutonomia, PDO::PARAM_INT);
    bindAcessoVeiculo($tanqueStmt, $usuario['id']);
    $tanqueStmt->execute();
    $tanqueLitros = $tanqueStmt->fetchColumn();
    if ($tanqueLitros !== false && $tanqueLitros !== null) {
        $autonomiaKm = (int) round((float) $tanqueLitros * $ultimaMedia);
    }
}

$lembretesStmt = $pdo->prepare(
    "SELECT l.descricao, l.tipo_alvo, l.km_alvo, l.data_alvo,
            (SELECT MAX(r.km_atual) FROM registros r WHERE r.veiculo_id = l.veiculo_id) AS km_atual_veiculo
     FROM lembretes l
     INNER JOIN veiculos v ON v.id = l.veiculo_id
     WHERE " . condicaoAcessoVeiculo('v') . " AND l.concluido_em IS NULL"
);
bindAcessoVeiculo($lembretesStmt, $usuario['id']);
$lembretesStmt->execute();
$lembretesAtencao = array_values(array_filter(
    array_map(static function ($l) {
        $status = calcularStatusLembrete($l)['status'];
        return $status === 'ok' ? null : ['descricao' => $l['descricao'], 'status' => $status];
    }, $lembretesStmt->fetchAll())
));
usort($lembretesAtencao, static fn($a, $b) => $a['status'] === 'vencido' ? -1 : ($b['status'] === 'vencido' ? 1 : 0));

// Alertas inteligentes não lidos (anomalias de consumo/preço/odômetro
// detectadas ao salvar registros — ver detectarAnomaliasRegistro()).
$alertasStmt = $pdo->prepare(
    "SELECT id, severidade, titulo, mensagem
     FROM alertas
     WHERE usuario_id = :usuario_id AND lido_em IS NULL
     ORDER BY FIELD(severidade, 'critico', 'atencao', 'info'), criado_em DESC
     LIMIT 5"
);
$alertasStmt->execute([':usuario_id' => $usuario['id']]);
$alertasNaoLidos = $alertasStmt->fetchAll();

$sqlRegistros = 'SELECT r.id, r.data, r.km_atual, r.tipo_registro, r.combustivel, r.litros, r.categoria_despesa, r.valor_pago, r.descricao, v.nome AS veiculo_nome,
                         EXISTS(SELECT 1 FROM registro_fotos rf WHERE rf.registro_id = r.id) AS tem_foto
                  FROM registros r
                  INNER JOIN veiculos v ON v.id = r.veiculo_id
                  WHERE ' . condicaoAcessoVeiculo('v')
    . ($veiculoIdFiltro !== null ? ' AND r.veiculo_id = :veiculo_id' : '')
    . ' ORDER BY r.data DESC, r.id DESC LIMIT 10';

$stmt = $pdo->prepare($sqlRegistros);
bindAcessoVeiculo($stmt, $usuario['id']);
if ($veiculoIdFiltro !== null) {
    $stmt->bindValue(':veiculo_id', $veiculoIdFiltro, PDO::PARAM_INT);
}
$stmt->execute();
$registros = $stmt->fetchAll();

$sqlGastoMes = 'SELECT COALESCE(SUM(r.valor_pago), 0)
                 FROM registros r
                 INNER JOIN veiculos v ON v.id = r.veiculo_id
                 WHERE ' . condicaoAcessoVeiculo('v') . '
                   AND DATE_FORMAT(r.data, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")'
    . ($veiculoIdFiltro !== null ? ' AND r.veiculo_id = :veiculo_id' : '');
$stmt = $pdo->prepare($sqlGastoMes);
bindAcessoVeiculo($stmt, $usuario['id']);
if ($veiculoIdFiltro !== null) {
    $stmt->bindValue(':veiculo_id', $veiculoIdFiltro, PDO::PARAM_INT);
}
$stmt->execute();
$gastoMes = (float) $stmt->fetchColumn();

$metaStmt = $pdo->prepare('SELECT meta_mensal FROM usuarios WHERE id = :id');
$metaStmt->execute([':id' => $usuario['id']]);
$metaMensalValor = $metaStmt->fetchColumn();
$metaMensal = $metaMensalValor !== null ? (float) $metaMensalValor : null;

// Projeção simples: mantendo o ritmo de gasto diário do mês corrente até o fim dele.
$hoje = new DateTime('today');
$diaAtual = (int) $hoje->format('j');
$diasNoMes = (int) $hoje->format('t');
$projecaoMes = $gastoMes > 0 ? ($gastoMes / $diaAtual) * $diasNoMes : 0.0;

$tituloPagina = 'PitStop BR';
require __DIR__ . '/includes/header.php';
?>

<div class="card card-resumo">
    <div class="card-body text-center py-4">
        <p class="text-muted mb-1 small text-uppercase">Última Média</p>
        <?php if ($ultimaMedia !== null):
            $kmlReferencia = 20.0; // faixa visual de referência (a maioria dos veículos fica entre 5 e 20 km/l)
            $percentual = max(0.0, min(1.0, $ultimaMedia / $kmlReferencia));
        ?>
        <div class="medidor" data-valor="<?= h(number_format($ultimaMedia, 1, '.', '')) ?>" data-percentual="<?= h((string) round($percentual, 4)) ?>">
            <svg viewBox="0 0 200 130" class="medidor-svg" aria-hidden="true">
                <path d="M 20 110 A 80 80 0 0 1 180 110" class="medidor-arco-fundo" />
                <path d="M 20 110 A 80 80 0 0 1 180 110" class="medidor-arco-valor" />
            </svg>
            <div class="medidor-leitura">
                <span class="medidor-valor">0,0</span><span class="medidor-unidade">km/l</span>
            </div>
        </div>
        <?php elseif ($aguardandoTanqueCheio && $mediaEstimativa !== null): ?>
        <h2 class="display-6 fw-bold text-warning mb-0"><i class="bi bi-question-diamond me-1"></i><?= h(number_format($mediaEstimativa, 1, ',', '.')) ?> km/l</h2>
        <p class="text-warning small mb-0 mt-1">
            <i class="bi bi-exclamation-triangle me-1"></i>Estimativa não confirmada — nenhum abastecimento recente encheu o tanque, então esse número pode estar bem errado. Encha o tanque no próximo abastecimento pra ter a média real.
        </p>
        <?php elseif ($aguardandoTanqueCheio): ?>
        <h2 class="display-6 fw-bold text-warning mb-0"><i class="bi bi-fuel-pump me-1"></i>Aguardando</h2>
        <p class="text-muted small mb-0 mt-1">Falta um abastecimento com "Encheu o tanque" marcado pra fechar a conta</p>
        <?php else: ?>
        <h2 class="display-6 fw-bold text-success mb-0"><i class="bi bi-speedometer2 me-1"></i>Sem dados</h2>
        <?php endif; ?>

        <?php if ($autonomiaKm !== null): ?>
        <div class="gasto-mes-linha mt-2">
            <span class="icone-chip icone-chip-teal" aria-hidden="true"><i class="bi bi-signpost-split"></i></span>
            <span class="text-start">
                <span class="text-muted small d-block">Autonomia estimada</span>
                <span class="valor-destaque">~<?= h(number_format($autonomiaKm, 0, ',', '.')) ?> km</span>
            </span>
        </div>
        <?php endif; ?>

        <div class="gasto-mes-linha">
            <span class="icone-chip icone-chip-laranja" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
            <span class="text-start">
                <span class="text-muted small d-block">Gasto este mês</span>
                <span class="valor-destaque"><?= h(formatarMoeda($gastoMes)) ?></span>
            </span>
        </div>

        <?php if ($gastoMes > 0 && $metaMensal === null): ?>
        <p class="small text-muted mt-2 mb-0">
            <i class="bi bi-graph-up-arrow me-1"></i>Projeção do mês: <?= h(formatarMoeda($projecaoMes)) ?> no ritmo atual.
        </p>
        <?php endif; ?>

        <?php if ($metaMensal !== null && $metaMensal > 0):
            $percentualMeta = $gastoMes / $metaMensal;
            $percentualBarra = max(0.0, min(1.0, $percentualMeta));
            $estadoMeta = $percentualMeta >= 1.0 ? 'estourada' : ($percentualMeta >= 0.7 ? 'atencao' : 'ok');
        ?>
        <div class="meta-mensal mt-3">
            <div class="meta-mensal-legenda">
                <span class="text-muted small">Meta do mês: <?= h(formatarMoeda($metaMensal)) ?></span>
                <span class="small fw-semibold meta-mensal-percentual meta-mensal-<?= $estadoMeta ?>">
                    <?= h(number_format($percentualMeta * 100, 0, ',', '.')) ?>%
                </span>
            </div>
            <div class="meta-mensal-trilho">
                <div class="meta-mensal-progresso meta-mensal-<?= $estadoMeta ?>" style="width: <?= h((string) round($percentualBarra * 100, 2)) ?>%"></div>
            </div>
            <?php if ($estadoMeta === 'estourada'): ?>
            <p class="small text-danger mb-0 mt-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Meta estourada em <?= h(formatarMoeda($gastoMes - $metaMensal)) ?>.</p>
            <?php else: ?>
            <p class="small text-muted mb-0 mt-1">Faltam <?= h(formatarMoeda($metaMensal - $gastoMes)) ?> pra bater a meta.</p>
                <?php if ($projecaoMes > $metaMensal): ?>
                <p class="small text-warning-emphasis mb-0 mt-1"><i class="bi bi-graph-up-arrow me-1"></i>No ritmo atual, a projeção do mês (<?= h(formatarMoeda($projecaoMes)) ?>) vai passar da meta.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($conquistas['totalAbastecimentos'] > 0): ?>
<div class="card card-resumo card-conquistas">
    <div class="card-body py-3">
        <?php if ($conquistas['sequenciaMeses'] >= 2): ?>
        <p class="text-center mb-2 conquistas-sequencia">
            <i class="bi bi-fire"></i> <?= (int) $conquistas['sequenciaMeses'] ?> meses seguidos registrando
        </p>
        <?php endif; ?>
        <div class="conquistas-linha">
            <?php foreach ($conquistas['badges'] as $b): ?>
            <span class="badge-conquista <?= $b['conquistada'] ? 'conquistada' : '' ?>" title="<?= h($b['titulo']) ?>">
                <i class="bi <?= h($b['icone']) ?>" aria-hidden="true"></i>
                <span class="badge-conquista-titulo"><?= h($b['titulo']) ?></span>
            </span>
            <?php endforeach; ?>
        </div>
        <?php if ($conquistas['proximoMarco'] !== null):
            $faltam = $conquistas['proximoMarco']['qtd'] - $conquistas['totalAbastecimentos'];
        ?>
        <p class="text-muted small text-center mb-0 mt-2">
            Faltam <strong><?= $faltam ?></strong> abastecimento<?= $faltam === 1 ? '' : 's' ?> pra "<?= h($conquistas['proximoMarco']['titulo']) ?>"
        </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($alertasNaoLidos): ?>
<div class="mx-1 mb-3" id="listaAlertas" data-csrf="<?= h(csrfToken()) ?>">
    <?php foreach ($alertasNaoLidos as $a):
        $classeAlerta = ['critico' => 'alert-danger', 'atencao' => 'alert-warning', 'info' => 'alert-info'][$a['severidade']];
        $iconeAlerta  = ['critico' => 'bi-exclamation-octagon-fill', 'atencao' => 'bi-exclamation-triangle-fill', 'info' => 'bi-info-circle-fill'][$a['severidade']];
    ?>
    <div class="alert <?= $classeAlerta ?> py-2 px-3 d-flex align-items-start gap-2 mb-2 alerta-item" data-alerta-id="<?= (int) $a['id'] ?>">
        <i class="bi <?= $iconeAlerta ?> mt-1"></i>
        <span class="small flex-grow-1"><strong><?= h($a['titulo']) ?></strong><br><?= h($a['mensagem']) ?></span>
        <button type="button" class="btn-close btn-close-alerta" aria-label="Dispensar alerta"></button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<a href="combustivel.php" class="d-flex align-items-center gap-2 mx-1 mb-3 text-decoration-none atalho-ferramenta">
    <span class="icone-chip icone-chip-teal" aria-hidden="true"><i class="bi bi-fuel-pump"></i></span>
    <span class="small fw-semibold">Etanol × Gasolina: qual compensa?</span>
    <i class="bi bi-chevron-right ms-auto text-muted small" aria-hidden="true"></i>
</a>

<?php if ($lembretesAtencao): ?>
<a href="lembretes.php" class="alert <?= $lembretesAtencao[0]['status'] === 'vencido' ? 'alert-danger' : 'alert-warning' ?> py-2 px-3 d-flex align-items-center gap-2 mx-1 mb-3 text-decoration-none">
    <i class="bi bi-bell-fill"></i>
    <span class="small">
        <?php if (count($lembretesAtencao) === 1): ?>
            <strong><?= h($lembretesAtencao[0]['descricao']) ?></strong> <?= $lembretesAtencao[0]['status'] === 'vencido' ? 'está vencido' : 'está próximo do prazo' ?>.
        <?php else: ?>
            Você tem <strong><?= count($lembretesAtencao) ?> lembretes</strong> vencidos ou próximos do prazo.
        <?php endif; ?>
    </span>
</a>
<?php endif; ?>

<?php if (count($veiculos) > 1): ?>
<form method="get" class="px-1 mb-3" id="formFiltroVeiculo">
    <select name="veiculo_id" class="form-select" id="selectVeiculoFiltro">
        <option value="">Todos os veículos</option>
        <?php foreach ($veiculos as $v): ?>
        <option value="<?= (int) $v['id'] ?>" <?= $veiculoIdFiltro === (int) $v['id'] ? 'selected' : '' ?>>
            <?= h($v['nome']) ?> (<?= h($v['tipo']) ?>)
        </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<div class="lista-registros px-1">
    <div class="d-flex justify-content-between align-items-center mb-2 px-1">
        <h6 class="text-muted mb-0">Registros Recentes</h6>
        <?php if ($veiculos): ?>
        <a href="adicionar.php" class="btn btn-primary btn-sm d-none d-lg-inline-flex align-items-center gap-1">
            <i class="bi bi-plus-lg"></i>Novo Registro
        </a>
        <?php endif; ?>
    </div>

    <?php if (!$registros): ?>
        <div class="estado-vazio">
            <i class="bi bi-fuel-pump estado-vazio-icone" aria-hidden="true"></i>
            <p class="estado-vazio-titulo">Nenhum registro ainda</p>
            <p class="estado-vazio-texto">
                <?= $veiculos
                    ? 'Adicione seu primeiro abastecimento ou manutenção pra começar a acompanhar consumo e gasto.'
                    : 'Cadastre um veículo primeiro pra começar a registrar abastecimentos.' ?>
            </p>
            <a href="<?= $veiculos ? 'adicionar.php' : 'veiculos.php' ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i><?= $veiculos ? 'Novo Registro' : 'Cadastrar Veículo' ?>
            </a>
        </div>
    <?php else: ?>
        <?php foreach ($registros as $r):
            $badgeInfo = [
                'Abastecimento' => ['bg-success', 'bi-fuel-pump', 'Abastecimento'],
                'Manutencao'    => ['bg-warning text-dark', 'bi-tools', 'Manutenção'],
                'Despesa'       => ['bg-info text-dark', 'bi-receipt', 'Despesa'],
            ][$r['tipo_registro']];
        ?>
        <div class="card shadow-sm mb-2">
            <div class="card-body py-2 px-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="registro-info">
                        <span class="badge <?= $badgeInfo[0] ?> mb-1">
                            <i class="bi <?= $badgeInfo[1] ?> me-1"></i><?= h($badgeInfo[2]) ?>
                        </span>
                        <div class="fw-semibold"><?= h($r['veiculo_nome']) ?></div>
                        <div class="text-muted small">
                            <?= h((new DateTime($r['data']))->format('d/m/Y')) ?> · <?= number_format((float) $r['km_atual'], 0, ',', '.') ?> km
                            <?php if ($r['litros']): ?> · <?= number_format((float) $r['litros'], 2, ',', '.') ?> L<?php endif; ?>
                        </div>
                        <?php if ($r['combustivel']): ?>
                        <div class="text-muted small">
                            <?= h($r['combustivel']) ?>
                            <?php if ((float) $r['litros'] > 0): ?> · <?= h(formatarMoeda((float) $r['valor_pago'] / (float) $r['litros'])) ?>/L<?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($r['categoria_despesa']): ?>
                        <div class="text-muted small"><?= h($r['categoria_despesa']) ?></div>
                        <?php endif; ?>
                        <?php if ($r['descricao']): ?><div class="text-muted small fst-italic"><?= h($r['descricao']) ?></div><?php endif; ?>
                        <?php if ($r['tem_foto']): ?>
                        <a href="foto.php?registro_id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener" class="text-muted small">
                            <i class="bi bi-camera-fill me-1"></i>Ver comprovante
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="text-end registro-valor-col">
                        <div class="fw-bold mb-1 valor-registro"><?= h(formatarMoeda((float) $r['valor_pago'])) ?></div>
                        <a href="registro_editar.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-1">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="post" action="excluir.php" class="form-excluir d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="assets/js/index.js"></script>
<script src="assets/js/animacoes.js"></script>
<?php if (($_GET['diag'] ?? '') === '1'): ?>
<script src="assets/js/diag.js"></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
