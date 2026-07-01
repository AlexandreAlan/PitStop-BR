document.addEventListener('DOMContentLoaded', function () {
    var camposAbastecimento = document.querySelectorAll('.campo-abastecimento');
    var camposDespesa = document.querySelectorAll('.campo-despesa');
    var radios = document.querySelectorAll('input[name="tipo_registro"]');

    function atualizarCampos(tipo) {
        camposAbastecimento.forEach(function (campo) {
            campo.classList.toggle('d-none', tipo !== 'Abastecimento');
        });
        camposDespesa.forEach(function (campo) {
            campo.classList.toggle('d-none', tipo !== 'Despesa');
        });
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (radio.checked) {
                atualizarCampos(radio.value);
            }
        });
    });
});
