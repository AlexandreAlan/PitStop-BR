<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$tituloPagina = 'Etanol × Gasolina — PitStop BR';
$mostrarVoltar = true;
require __DIR__ . '/includes/header.php';
?>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <h6 class="text-muted mb-1"><i class="bi bi-fuel-pump me-1"></i>Vale a pena abastecer com Etanol?</h6>
        <p class="small text-muted mb-3">Regra prática: na maioria dos carros flex, o etanol rende cerca de 70%
        do que a gasolina rende por litro. Se o preço dele for até 70% do preço da gasolina, compensa.
        Se você já sabe o rendimento real do seu carro, ajuste o limiar abaixo.</p>

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
