<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

// Página pública, sem login: acessível por qualquer um que tenha o link
// (token único de 32 bytes, não sequencial e não adivinhável — ver
// criarOuRotacionarPassaporte() em includes/functions.php). Read-only por
// design: não existe nenhuma ação de escrita nesta página, e todo dado
// exibido é sempre filtrado pelo veiculo_id/usuario_id resolvidos a partir
// do token, nunca por parâmetro solto da requisição — impede o link de um
// veículo vazar dados de outro veículo ou de outra conta (IDOR).

$token = (string) ($_GET['token'] ?? '');
$contexto = buscarVeiculoPorTokenPassaporte($pdo, $token);

if ($contexto === null) {
    http_response_code(404);
    $tituloPagina = 'Link Inválido — PitStop BR';
    $telaAuth = true;
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card shadow-sm border-0">
        <div class="card-body p-4 text-center">
            <p class="mb-3">Este link é inválido ou foi revogado pelo dono do veículo.</p>
            <a href="index.php" class="btn btn-outline-primary">Ir para o PitStop BR</a>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$veiculoId = $contexto['veiculo_id'];
$usuarioIdDono = $contexto['usuario_id'];

$stmt = $pdo->prepare('SELECT nome, tipo, cor, placa, tanque_litros FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
$stmt->execute([':id' => $veiculoId, ':usuario_id' => $usuarioIdDono]);
$veiculo = $stmt->fetch();

// Não pode acontecer (o token só existe enquanto o veículo existir, FK
// ON DELETE CASCADE), mas se acontecer é melhor tratar como link inválido
// do que estourar um erro pro visitante público.
if (!$veiculo) {
    http_response_code(404);
    die('Veículo não encontrado.');
}

$stats = calcularEstatisticasVeiculo($pdo, $usuarioIdDono, $veiculoId, null, null);

$kmAtualStmt = $pdo->prepare('SELECT MAX(km_atual) FROM registros WHERE veiculo_id = :veiculo_id');
$kmAtualStmt->execute([':veiculo_id' => $veiculoId]);
$kmAtual = $kmAtualStmt->fetchColumn();

$registrosStmt = $pdo->prepare(
    'SELECT data, km_atual, tipo_registro, combustivel, litros, tanque_cheio, categoria_despesa, valor_pago, descricao
     FROM registros WHERE veiculo_id = :veiculo_id
     ORDER BY data ASC, km_atual ASC'
);
$registrosStmt->execute([':veiculo_id' => $veiculoId]);
$registros = $registrosStmt->fetchAll();

$tituloPagina = 'Passaporte de ' . $veiculo['nome'] . ' — PitStop BR';
$mostrarVoltar = false;
require __DIR__ . '/includes/header.php';
?>

<div class="px-1 mb-3">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h5 class="mb-1"><?= h($veiculo['nome']) ?></h5>
            <p class="text-muted small mb-0">
                <?= h($veiculo['tipo']) ?><?= $veiculo['cor'] ? ' · ' . h($veiculo['cor']) : '' ?><?= $veiculo['placa'] ? ' · ' . h($veiculo['placa']) : '' ?>
            </p>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="botaoImprimirPassaporte">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </button>
    </div>
    <p class="text-muted small mt-2 mb-0">
        <i class="bi bi-shield-check me-1"></i>Histórico somente leitura, compartilhado pelo dono do veículo.
    </p>
</div>

<div class="row gx-2 px-1 mb-3">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <p class="text-muted small mb-1">Km Atual</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $kmAtual !== null ? h(number_format((float) $kmAtual, 0, ',', '.')) . ' km' : '—' ?></p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <p class="text-muted small mb-1">Consumo Médio</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $stats['consumo_medio'] !== null ? h(number_format($stats['consumo_medio'], 1, ',', '.')) . ' km/l' : '—' ?></p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <p class="text-muted small mb-1">Custo por Km</p>
                <p class="fw-bold mb-0 small stat-valor"><?= $stats['custo_km'] !== null ? h(formatarMoeda($stats['custo_km'])) : '—' ?></p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-2 text-center">
                <p class="text-muted small mb-1">Total Registrado</p>
                <p class="fw-bold mb-0 small stat-valor"><?= h(formatarMoeda($stats['gasto'])) ?></p>
            </div>
        </div>
    </div>
</div>

<div class="px-1 mb-4">
    <h6 class="text-muted mb-2">Histórico Completo</h6>
    <?php if (!$registros): ?>
        <div class="estado-vazio-mini"><i class="bi bi-clock-history" aria-hidden="true"></i>Nenhum registro ainda.</div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-body p-0" style="overflow-x: auto;">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="small ps-3">Data</th>
                            <th class="small">Tipo</th>
                            <th class="small text-end">Km</th>
                            <th class="small">Detalhe</th>
                            <th class="small text-end pe-3">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $r):
                            $rotuloTipo = ['Abastecimento' => 'Abastecimento', 'Manutencao' => 'Manutenção', 'Despesa' => 'Despesa'][$r['tipo_registro']];
                            $detalhe = $r['tipo_registro'] === 'Abastecimento'
                                ? h((string) $r['combustivel']) . ' · ' . h(number_format((float) $r['litros'], 2, ',', '.')) . ' L'
                                    . ((int) $r['tanque_cheio'] === 1 ? ' · cheio' : ' · parcial')
                                : ($r['tipo_registro'] === 'Despesa' ? h((string) $r['categoria_despesa']) : h((string) ($r['descricao'] ?? '')));
                        ?>
                        <tr>
                            <td class="small ps-3"><?= h((new DateTime($r['data']))->format('d/m/Y')) ?></td>
                            <td class="small"><?= h($rotuloTipo) ?></td>
                            <td class="small text-end"><?= h(number_format((float) $r['km_atual'], 0, ',', '.')) ?></td>
                            <td class="small"><?= $detalhe ?></td>
                            <td class="small text-end pe-3"><?= h(formatarMoeda((float) $r['valor_pago'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="assets/js/passaporte_publico.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
