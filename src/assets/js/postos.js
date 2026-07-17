document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.form-excluir-posto').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm('Excluir este posto? Os registros já feitos com ele continuam, só ficam sem posto vinculado.')) {
                event.preventDefault();
            }
        });
    });
});
