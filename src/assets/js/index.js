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
});
