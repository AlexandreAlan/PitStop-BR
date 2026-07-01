document.addEventListener('DOMContentLoaded', function () {
    var camposKm = document.querySelectorAll('.campo-alvo-km');
    var camposData = document.querySelectorAll('.campo-alvo-data');
    var radios = document.querySelectorAll('input[name="tipo_alvo"]');

    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!radio.checked) {
                return;
            }
            camposKm.forEach(function (campo) {
                campo.classList.toggle('d-none', radio.value !== 'KM');
            });
            camposData.forEach(function (campo) {
                campo.classList.toggle('d-none', radio.value !== 'Data');
            });
        });
    });

    document.querySelectorAll('.form-excluir-lembrete').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm('Excluir este lembrete?')) {
                event.preventDefault();
            }
        });
    });
});
