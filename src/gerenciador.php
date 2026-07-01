<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$admin = exigirAdmin();

/**
 * Só dados agregados por conta (contagens e somas) — nunca o detalhe de um
 * registro específico (descrição, categoria etc.) — pra não expor mais do
 * que o necessário pra administrar o sistema (minimização de dados, LGPD).
 */
$sql = "SELECT
            u.id, u.nome, u.email, u.role, u.criado_em, u.email_verificado_em,
            (SELECT COUNT(*) FROM veiculos v WHERE v.usuario_id = u.id) AS total_veiculos,
            (SELECT COUNT(*) FROM registros r INNER JOIN veiculos v ON v.id = r.veiculo_id WHERE v.usuario_id = u.id) AS total_registros,
            (SELECT COALESCE(SUM(r.valor_pago), 0) FROM registros r INNER JOIN veiculos v ON v.id = r.veiculo_id WHERE v.usuario_id = u.id) AS gasto_total,
            (SELECT MAX(r.criado_em) FROM registros r INNER JOIN veiculos v ON v.id = r.veiculo_id WHERE v.usuario_id = u.id) AS ultima_atividade
        FROM usuarios u
        ORDER BY u.criado_em DESC";
$contas = $pdo->query($sql)->fetchAll();

$totalContas = count($contas);
$totalGeral = array_sum(array_column($contas, 'gasto_total'));

$tituloPagina = 'Painel Administrativo — PitStop BR';
$mostrarVoltar = true;
require __DIR__ . '/includes/header.php';
?>

<div class="row row-cols-2 g-2 px-1 mb-3">
    <div class="col">
        <div class="card shadow-sm border-0 text-center py-3">
            <div class="fw-bold fs-4"><?= $totalContas ?></div>
            <div class="text-muted small">Contas cadastradas</div>
        </div>
    </div>
    <div class="col">
        <div class="card shadow-sm border-0 text-center py-3">
            <div class="fw-bold fs-4"><?= h(formatarMoeda((float) $totalGeral)) ?></div>
            <div class="text-muted small">Movimentado no total</div>
        </div>
    </div>
</div>

<div class="lista-registros px-1">
    <h6 class="text-muted mb-2 px-1">Contas</h6>
    <?php foreach ($contas as $c): ?>
    <div class="card shadow-sm mb-2">
        <div class="card-body py-2 px-3">
            <div class="d-flex justify-content-between align-items-start">
                <div class="registro-info">
                    <div class="fw-semibold">
                        <?= h($c['nome']) ?>
                        <?php if ($c['role'] === 'admin'): ?><span class="badge bg-dark ms-1">admin</span><?php endif; ?>
                        <?php if ($c['email_verificado_em'] === null): ?><span class="badge bg-secondary ms-1">e-mail não confirmado</span><?php endif; ?>
                    </div>
                    <div class="text-muted small"><?= h($c['email']) ?></div>
                    <div class="text-muted small">
                        <?= (int) $c['total_veiculos'] ?> veículo(s) · <?= (int) $c['total_registros'] ?> registro(s)
                        <?php if ($c['ultima_atividade']): ?> · última atividade em <?= h((new DateTime($c['ultima_atividade']))->format('d/m/Y')) ?><?php endif; ?>
                    </div>
                    <div class="text-muted small">Conta criada em <?= h((new DateTime($c['criado_em']))->format('d/m/Y')) ?></div>
                </div>
                <div class="text-end registro-valor-col">
                    <div class="fw-bold valor-registro"><?= h(formatarMoeda((float) $c['gasto_total'])) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
