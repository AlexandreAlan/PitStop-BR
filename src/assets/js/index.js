document.addEventListener('DOMContentLoaded', function () {
    var select = document.getElementById('selectVeiculoFiltro');
    if (select) {
        select.addEventListener('change', function () {
            document.getElementById('formFiltroVeiculo').submit();
        });
    }

    document.querySelectorAll('.form-excluir').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm('Excluir este registro?')) {
                event.preventDefault();
            }
        });
    });

    var listaAlertas = document.getElementById('listaAlertas');
    if (listaAlertas) {
        var csrfToken = listaAlertas.getAttribute('data-csrf');
        listaAlertas.querySelectorAll('.btn-close-alerta').forEach(function (botao) {
            botao.addEventListener('click', function () {
                var item = botao.closest('.alerta-item');
                if (!item) {
                    return;
                }
                botao.disabled = true;
                fetch('api/alerta_lido.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: csrfToken, id: item.getAttribute('data-alerta-id') }),
                }).then(function () {
                    item.remove();
                }).catch(function (erro) {
                    console.error('[alertas] falha ao marcar como lido:', erro);
                    botao.disabled = false;
                });
            });
        });
    }
});
