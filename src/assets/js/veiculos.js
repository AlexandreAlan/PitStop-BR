document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.form-excluir-veiculo').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm('Excluir este veículo? Todo o histórico de abastecimentos e manutenções dele também será apagado.')) {
                event.preventDefault();
            }
        });
    });

    inicializarBuscaModelo();
});

/**
 * Busca no catálogo (api/buscar_modelo.php) enquanto o usuário digita
 * "marca modelo ano" (ex.: "bros 160 2025") e autopreenche tanque/peso ao
 * escolher um resultado — sem travar o formulário se não achar nada, os
 * campos continuam editáveis à mão.
 */
function inicializarBuscaModelo() {
    const campoBusca = document.getElementById('buscaModelo');
    if (!campoBusca) return;

    const lista = document.getElementById('buscaModeloResultados');
    const campoModeloId = document.getElementById('modeloVeiculoId');
    const campoTanque = document.getElementById('tanqueLitros');
    const campoPeso = document.getElementById('pesoKg');
    let ultimoController = null;
    let timeoutId = null;

    campoBusca.addEventListener('input', function () {
        // Digitou de novo depois de já ter escolhido um modelo: descarta o
        // vínculo antigo (autopreenchimento passa a valer só pra escolha nova).
        campoModeloId.value = '';

        const termo = campoBusca.value.trim();
        clearTimeout(timeoutId);
        if (termo.length < 2) {
            lista.classList.add('d-none');
            lista.innerHTML = '';
            return;
        }

        timeoutId = setTimeout(function () { buscar(termo); }, 300);
    });

    document.addEventListener('click', function (evento) {
        if (evento.target !== campoBusca && !lista.contains(evento.target)) {
            lista.classList.add('d-none');
        }
    });

    async function buscar(termo) {
        if (ultimoController) ultimoController.abort();
        ultimoController = new AbortController();

        try {
            const resp = await fetch('api/buscar_modelo.php?q=' + encodeURIComponent(termo), {
                credentials: 'same-origin',
                signal: ultimoController.signal,
            });
            if (!resp.ok) return;
            const dados = await resp.json();
            renderizarResultados(dados.resultados || []);
        } catch (erro) {
            // busca cancelada (nova digitação) ou sem internet — sem problema,
            // o usuário pode preencher tanque/peso à mão.
        }
    }

    function renderizarResultados(resultados) {
        if (!resultados.length) {
            lista.classList.add('d-none');
            lista.innerHTML = '';
            return;
        }

        lista.innerHTML = resultados.map(function (r) {
            const periodo = r.ano_fim ? (r.ano_inicio + '–' + r.ano_fim) : (r.ano_inicio + '+');
            return '<button type="button" class="list-group-item list-group-item-action busca-modelo-item" ' +
                'data-id="' + r.id + '" data-tanque="' + (r.tanque_litros ?? '') + '" data-peso="' + (r.peso_kg ?? '') + '" ' +
                'data-label="' + escaparAtributo(r.marca + ' ' + r.modelo) + '">' +
                '<div class="fw-semibold">' + escaparTexto(r.marca + ' ' + r.modelo) + '</div>' +
                '<div class="text-muted small">' + escaparTexto(r.tipo) + ' · ' + periodo +
                (r.tanque_litros ? ' · tanque ' + r.tanque_litros + 'L' : '') +
                (r.peso_kg ? ' · ' + r.peso_kg + 'kg' : '') + '</div>' +
                '</button>';
        }).join('');
        lista.classList.remove('d-none');

        lista.querySelectorAll('.busca-modelo-item').forEach(function (item) {
            item.addEventListener('click', function () {
                campoBusca.value = item.getAttribute('data-label');
                campoModeloId.value = item.getAttribute('data-id');
                const tanque = item.getAttribute('data-tanque');
                const peso = item.getAttribute('data-peso');
                if (tanque && campoTanque && !campoTanque.value) campoTanque.value = tanque;
                if (peso && campoPeso && !campoPeso.value) campoPeso.value = peso;
                lista.classList.add('d-none');
            });
        });
    }

    function escaparTexto(texto) {
        const div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }

    function escaparAtributo(texto) {
        return escaparTexto(texto).replace(/"/g, '&quot;');
    }
}
