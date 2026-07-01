<?php
declare(strict_types=1);

/**
 * Versão exibida no rodapé do app e usada pelo Service Worker pra saber
 * quando invalidar o cache. O changelog é escrito em linguagem simples —
 * é o que aparece pro usuário no aviso de atualização (não é o CHANGELOG
 * técnico do README).
 */
const APP_VERSION = '1.6.4';

const APP_CHANGELOG = [
    [
        'versao'  => '1.6.4',
        'resumo'  => 'Correção séria do modo offline: em alguns casos o app guardava a tela de login no lugar dos seus dados, fazendo parecer que o app "não funcionava" sem internet. Essa versão limpa o cache antigo automaticamente — feche e abra o app uma vez pra garantir.',
    ],
    [
        'versao'  => '1.6.3',
        'resumo'  => 'A partir de agora, quando sai uma correção, o app recarrega sozinho pra aplicá-la — sem precisar fechar e abrir na mão. Se você chegou a ver telas fora do padrão nas versões anteriores, feche e abra o app mais uma vez pra garantir que essa correção específica seja carregada.',
    ],
    [
        'versao'  => '1.6.2',
        'resumo'  => 'Correção do layout aparecendo como "de computador" (menu lateral, colunas largas) em alguns celulares/aplicativo instalado — o modo desktop agora só ativa com mouse de verdade, nunca em toque.',
    ],
    [
        'versao'  => '1.6.1',
        'resumo'  => 'Correção de um bug que fazia o app perder a formatação (cores, ícones e fontes) depois de logar, causado pelo modo offline novo. Se o app ainda parecer fora do padrão, feche e abra de novo — ou limpe os dados do site — pra garantir que a versão nova seja carregada.',
    ],
    [
        'versao'  => '1.6.0',
        'resumo'  => 'Agora o PitStop BR funciona sem internet: dá pra ver seus dados e registrar abastecimentos mesmo sem sinal — tudo sincroniza sozinho assim que a conexão voltar. Também deixamos as telas mais fáceis de ler no celular.',
    ],
    [
        'versao'  => '1.5.0',
        'resumo'  => 'Chegaram os Lembretes de manutenção e documentos (óleo, revisão, seguro), o controle de Despesas (IPVA, seguro, multa...) e os relatórios agora podem ser filtrados por período e exportados.',
    ],
];
