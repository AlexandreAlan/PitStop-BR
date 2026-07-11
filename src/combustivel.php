<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

// Litros padrão pra abrir a comparação de custo: capacidade do tanque, se o
// usuário tiver exatamente 1 veículo com isso cadastrado (mesmo critério de
// "sem ambiguidade" usado na Autonomia do dashboard). Sem isso, 12L — um
// valor redondo e típico de complemento de moto/carro pequeno.
$litrosPadrao = 12;
$veiculosStmt = $pdo->prepare('SELECT tanque_litros FROM veiculos WHERE usuario_id = :usuario_id');
$veiculosStmt->execute([':usuario_id' => $usuario['id']]);
$veiculosTanque = $veiculosStmt->fetchAll();
if (count($veiculosTanque) === 1 && $veiculosTanque[0]['tanque_litros'] !== null) {
    $litrosPadrao = (float) $veiculosTanque[0]['tanque_litros'];
}

$tituloPagina = 'Etanol × Gasolina — PitStop BR';
$mostrarVoltar = true;
require __DIR__ . '/includes/header.php';
?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body p-4">
        <h6 class="text-muted mb-1"><i class="bi bi-fuel-pump me-1"></i>Etanol × Gasolina</h6>
        <p class="small text-muted mb-3">Coloque o preço dos dois combustíveis no posto que você costuma abastecer
        e quantos litros pretende colocar — o app mostra quanto custa em cada um e se compensa trocar.</p>

        <div class="row g-2 mb-3">
            <div class="col-6">
                <label class="form-label">Etanol (R$/L)</label>
                <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="text" inputmode="decimal" id="precoEtanol" class="form-control" placeholder="3,79">
                </div>
            </div>
            <div class="col-6">
                <label class="form-label">Gasolina (R$/L)</label>
                <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="text" inputmode="decimal" id="precoGasolina" class="form-control" placeholder="5,89">
                </div>
            </div>
        </div>

        <label class="form-label">Quantos litros?</label>
        <div class="input-group mb-1" style="max-width: 220px;">
            <button type="button" class="btn btn-outline-secondary" id="botaoLitrosMenos" aria-label="Diminuir">
                <i class="bi bi-dash-lg"></i>
            </button>
            <input type="number" min="1" max="200" step="1" value="<?= h((string) $litrosPadrao) ?>" id="camposLitros" class="form-control text-center">
            <span class="input-group-text">L</span>
            <button type="button" class="btn btn-outline-secondary" id="botaoLitrosMais" aria-label="Aumentar">
                <i class="bi bi-plus-lg"></i>
            </button>
        </div>
    </div>
</div>

<div id="comparadorCusto" class="row g-2 mb-3 d-none">
    <div class="col-6">
        <div class="card shadow-sm border-0 h-100 cartao-combustivel" id="cartaoEtanol">
            <div class="card-body p-3 text-center">
                <span class="icone-chip icone-chip-teal mb-2" aria-hidden="true"><i class="bi bi-droplet-fill"></i></span>
                <p class="text-muted small mb-1">Etanol · <span id="litrosLabelEtanol">12</span>L</p>
                <p class="fw-bold mb-0 stat-valor fs-4" id="custoEtanol">—</p>
                <span class="badge rounded-pill d-none mt-2" id="seloEtanol"><i class="bi bi-trophy-fill me-1"></i>Mais barato</span>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card shadow-sm border-0 h-100 cartao-combustivel" id="cartaoGasolina">
            <div class="card-body p-3 text-center">
                <span class="icone-chip icone-chip-laranja mb-2" aria-hidden="true"><i class="bi bi-droplet-fill"></i></span>
                <p class="text-muted small mb-1">Gasolina · <span id="litrosLabelGasolina">12</span>L</p>
                <p class="fw-bold mb-0 stat-valor fs-4" id="custoGasolina">—</p>
                <span class="badge rounded-pill d-none mt-2" id="seloGasolina"><i class="bi bi-trophy-fill me-1"></i>Mais barato</span>
            </div>
        </div>
    </div>
    <div class="col-12 text-center">
        <p class="small text-muted mb-0" id="diferencaCusto"></p>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <h6 class="text-muted mb-1"><i class="bi bi-speedometer2 me-1"></i>Qual rende mais por km?</h6>
        <p class="small text-muted mb-3">Regra prática: na maioria dos carros flex, o etanol rende cerca de 70%
        do que a gasolina rende por litro. Se o preço dele for até 70% do preço da gasolina, compensa.
        Se você já sabe o rendimento real do seu carro, ajuste o limiar abaixo.</p>

        <div class="mb-1">
            <label class="form-label small text-muted mb-1">Limiar de compensação (%)</label>
            <input type="range" min="60" max="80" step="1" value="70" id="limiarCompensacao" class="form-range">
            <div class="form-text" id="limiarTexto">70% — média dos carros flex no Brasil.</div>
        </div>

        <div id="resultadoComparador" class="d-none mt-3">
            <div class="meta-mensal-trilho mb-2">
                <div class="meta-mensal-progresso" id="barraComparador"></div>
            </div>
            <div class="text-center">
                <p class="mb-1"><span class="text-muted small">Etanol está em</span>
                    <span class="fw-bold" id="percentualComparador">—</span>
                    <span class="text-muted small">do preço da gasolina</span></p>
                <p class="fs-5 fw-bold mb-0" id="veredito">—</p>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/combustivel.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
