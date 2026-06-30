document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.form-excluir-veiculo').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm('Excluir este veículo? Todo o histórico de abastecimentos e manutenções dele também será apagado.')) {
                event.preventDefault();
            }
        });
    });
});
