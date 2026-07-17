document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.form-remover-colaborador').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm('Remover esse colaborador? A pessoa perde o acesso a este veículo imediatamente.')) {
                event.preventDefault();
            }
        });
    });
});
