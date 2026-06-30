document.addEventListener('DOMContentLoaded', function () {
    var medidor = document.querySelector('.medidor');
    if (!medidor) {
        return;
    }

    var valor = parseFloat(medidor.dataset.valor || '0');
    var percentual = parseFloat(medidor.dataset.percentual || '0');
    var arco = medidor.querySelector('.medidor-arco-valor');
    var leitura = medidor.querySelector('.medidor-valor');
    var semMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var circunferencia = 251;
    var offsetFinal = circunferencia * (1 - percentual);

    if (semMovimento) {
        arco.style.strokeDashoffset = String(offsetFinal);
        leitura.textContent = valor.toFixed(1).replace('.', ',');
        return;
    }

    requestAnimationFrame(function () {
        arco.style.strokeDashoffset = String(offsetFinal);
    });

    var duracao = 1100;
    var inicio = null;

    function passo(timestamp) {
        if (inicio === null) {
            inicio = timestamp;
        }
        var progresso = Math.min((timestamp - inicio) / duracao, 1);
        var atual = valor * progresso;
        leitura.textContent = atual.toFixed(1).replace('.', ',');
        if (progresso < 1) {
            requestAnimationFrame(passo);
        }
    }

    requestAnimationFrame(passo);
});
