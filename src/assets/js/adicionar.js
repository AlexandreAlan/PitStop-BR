document.addEventListener('DOMContentLoaded', function () {
    var camposAbastecimento = document.querySelectorAll('.campo-abastecimento');
    var radios = document.querySelectorAll('input[name="tipo_registro"]');

    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            camposAbastecimento.forEach(function (campo) {
                campo.classList.toggle('d-none', radio.value === 'Manutencao' && radio.checked);
            });
        });
    });
});
