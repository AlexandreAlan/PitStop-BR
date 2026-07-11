document.addEventListener('DOMContentLoaded', function () {
    var campoEtanol = document.getElementById('precoEtanol');
    var campoGasolina = document.getElementById('precoGasolina');
    var sliderLimiar = document.getElementById('limiarCompensacao');
    var limiarTexto = document.getElementById('limiarTexto');
    var resultado = document.getElementById('resultadoComparador');
    var barra = document.getElementById('barraComparador');
    var percentualEl = document.getElementById('percentualComparador');
    var veredito = document.getElementById('veredito');

    var campoLitros = document.getElementById('camposLitros');
    var botaoLitrosMenos = document.getElementById('botaoLitrosMenos');
    var botaoLitrosMais = document.getElementById('botaoLitrosMais');
    var comparadorCusto = document.getElementById('comparadorCusto');
    var cartaoEtanol = document.getElementById('cartaoEtanol');
    var cartaoGasolina = document.getElementById('cartaoGasolina');
    var custoEtanolEl = document.getElementById('custoEtanol');
    var custoGasolinaEl = document.getElementById('custoGasolina');
    var seloEtanol = document.getElementById('seloEtanol');
    var seloGasolina = document.getElementById('seloGasolina');
    var litrosLabelEtanol = document.getElementById('litrosLabelEtanol');
    var litrosLabelGasolina = document.getElementById('litrosLabelGasolina');
    var diferencaCusto = document.getElementById('diferencaCusto');

    if (!campoEtanol || !campoGasolina) return;

    function paraNumero(valor) {
        var normalizado = valor.trim().replace(',', '.');
        var numero = parseFloat(normalizado);
        return isFinite(numero) && numero > 0 ? numero : null;
    }

    function formatarMoeda(v) {
        return 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function atualizarLimiarTexto() {
        var limiar = parseInt(sliderLimiar.value, 10);
        var contexto = limiar === 70 ? ' — média dos carros flex no Brasil.' : ' — ajustado pro seu carro.';
        limiarTexto.textContent = limiar + '%' + contexto;
    }

    // Comparador de custo: quanto custa encher a quantidade de litros
    // escolhida em cada combustível, lado a lado — pergunta direta e
    // concreta, independente do rendimento por km (isso fica no veredito
    // de baixo, que é quem decide qual realmente compensa).
    function recalcularCusto() {
        var etanol = paraNumero(campoEtanol.value);
        var gasolina = paraNumero(campoGasolina.value);
        var litros = parseFloat(campoLitros.value);

        if (!comparadorCusto) return;
        if (etanol === null || gasolina === null || !isFinite(litros) || litros <= 0) {
            comparadorCusto.classList.add('d-none');
            return;
        }

        var custoEtanol = etanol * litros;
        var custoGasolina = gasolina * litros;

        comparadorCusto.classList.remove('d-none');
        litrosLabelEtanol.textContent = litros;
        litrosLabelGasolina.textContent = litros;
        custoEtanolEl.textContent = formatarMoeda(custoEtanol);
        custoGasolinaEl.textContent = formatarMoeda(custoGasolina);

        var etanolMaisBarato = custoEtanol < custoGasolina;
        var empate = custoEtanol === custoGasolina;

        cartaoEtanol.classList.toggle('cartao-combustivel-vencedor', etanolMaisBarato && !empate);
        cartaoGasolina.classList.toggle('cartao-combustivel-vencedor', !etanolMaisBarato && !empate);
        seloEtanol.classList.toggle('d-none', empate || !etanolMaisBarato);
        seloGasolina.classList.toggle('d-none', empate || etanolMaisBarato);

        if (empate) {
            diferencaCusto.textContent = 'Mesmo custo nos dois — empate.';
        } else {
            var diferenca = Math.abs(custoEtanol - custoGasolina);
            var maisBarato = etanolMaisBarato ? 'Etanol' : 'Gasolina';
            diferencaCusto.textContent = maisBarato + ' sai ' + formatarMoeda(diferenca) + ' mais barato pra esses ' + litros + 'L.';
        }
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

    function recalcularTudo() {
        recalcularCusto();
        recalcular();
    }

    [campoEtanol, campoGasolina].forEach(function (campo) {
        campo.addEventListener('input', recalcularTudo);
    });
    if (campoLitros) {
        campoLitros.addEventListener('input', recalcularCusto);
    }
    if (botaoLitrosMenos) {
        botaoLitrosMenos.addEventListener('click', function () {
            campoLitros.value = Math.max(1, parseFloat(campoLitros.value || '0') - 1);
            recalcularCusto();
        });
    }
    if (botaoLitrosMais) {
        botaoLitrosMais.addEventListener('click', function () {
            campoLitros.value = Math.min(200, parseFloat(campoLitros.value || '0') + 1);
            recalcularCusto();
        });
    }
    sliderLimiar.addEventListener('input', function () {
        atualizarLimiarTexto();
        recalcular();
    });

    atualizarLimiarTexto();
    recalcularCusto();
});
