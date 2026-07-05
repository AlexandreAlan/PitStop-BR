document.addEventListener('DOMContentLoaded', function () {
    var campoEtanol = document.getElementById('precoEtanol');
    var campoGasolina = document.getElementById('precoGasolina');
    var sliderLimiar = document.getElementById('limiarCompensacao');
    var limiarTexto = document.getElementById('limiarTexto');
    var resultado = document.getElementById('resultadoComparador');
    var barra = document.getElementById('barraComparador');
    var percentualEl = document.getElementById('percentualComparador');
    var veredito = document.getElementById('veredito');

    if (!campoEtanol || !campoGasolina) return;

    function paraNumero(valor) {
        var normalizado = valor.trim().replace(',', '.');
        var numero = parseFloat(normalizado);
        return isFinite(numero) && numero > 0 ? numero : null;
    }

    function atualizarLimiarTexto() {
        var limiar = parseInt(sliderLimiar.value, 10);
        var contexto = limiar === 70 ? ' — média dos carros flex no Brasil.' : ' — ajustado pro seu carro.';
        limiarTexto.textContent = limiar + '%' + contexto;
    }

    function recalcular() {
        var etanol = paraNumero(campoEtanol.value);
        var gasolina = paraNumero(campoGasolina.value);
        var limiar = parseInt(sliderLimiar.value, 10);

        if (etanol === null || gasolina === null) {
            resultado.classList.add('d-none');
            return;
        }

        var razao = (etanol / gasolina) * 100;
        var compensaEtanol = razao <= limiar;

        resultado.classList.remove('d-none');
        barra.style.width = Math.min(razao, 100) + '%';
        barra.classList.remove('meta-mensal-ok', 'meta-mensal-estourada');
        percentualEl.classList.remove('meta-mensal-ok', 'meta-mensal-estourada');
        veredito.classList.remove('text-success', 'text-danger');

        var classeEstado = compensaEtanol ? 'meta-mensal-ok' : 'meta-mensal-estourada';
        barra.classList.add(classeEstado);
        percentualEl.classList.add(classeEstado);
        percentualEl.textContent = razao.toFixed(1).replace('.', ',') + '%';
        veredito.classList.add(compensaEtanol ? 'text-success' : 'text-danger');
        veredito.innerHTML = compensaEtanol
            ? '<i class="bi bi-check-circle-fill me-1"></i>Compensa abastecer com Etanol'
            : '<i class="bi bi-x-circle-fill me-1"></i>Compensa abastecer com Gasolina';
    }

    [campoEtanol, campoGasolina].forEach(function (campo) {
        campo.addEventListener('input', recalcular);
    });
    sliderLimiar.addEventListener('input', function () {
        atualizarLimiarTexto();
        recalcular();
    });

    atualizarLimiarTexto();
});
