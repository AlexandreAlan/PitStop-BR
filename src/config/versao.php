<?php
declare(strict_types=1);

/**
 * Versão exibida no rodapé do app e usada pelo Service Worker pra saber
 * quando invalidar o cache. O changelog é escrito em linguagem simples —
 * é o que aparece pro usuário no aviso de atualização (não é o CHANGELOG
 * técnico do README).
 */
const APP_VERSION = '1.8.0';

const APP_CHANGELOG = [
    [
        'versao'  => '1.8.0',
        'resumo'  => 'Nova Meta de Gasto Mensal: defina um teto de gasto por mês em Minha Conta e acompanhe o progresso com uma barra colorida no painel principal (verde até 70%, amarelo até estourar, vermelho quando passa da meta). Também corrigido um bug visual na página de instalação: a "impressão digital" (SHA-256) do APK estourava a largura da tela em vez de quebrar linha.',
    ],
    [
        'versao'  => '1.7.0',
        'resumo'  => 'Cadastro de veículo ganhou cor, placa e busca de modelo: digite algo como "Bros 160 2025" e o app preenche sozinho o tanque e o peso, usando um catálogo dos veículos mais comuns no Brasil. Relatórios agora mostram seu consumo real comparado com o de fábrica quando o veículo tem modelo vinculado.',
    ],
    [
        'versao'  => '1.6.9',
        'resumo'  => 'Removido o layout diferente de "computador" (menu lateral, colunas largas) que aparecia ao abrir no notebook/PC — agora fica exatamente igual ao celular em qualquer tamanho de tela, só centralizado.',
    ],
    [
        'versao'  => '1.6.8',
        'resumo'  => 'Correção do offline logo depois de instalar o app do zero: o cache de páginas só era recarregado na instalação (quando ainda não tinha login) ou numa atualização de versão — se você instalasse o app, logasse e fosse direto pro modo offline sem visitar cada tela na mão antes, ficava faltando página. Agora ele recarrega sozinho assim que percebe que você logou.',
    ],
    [
        'versao'  => '1.6.7',
        'resumo'  => 'Duas correções sérias do modo offline: (1) depois de cada atualização o cache antigo era apagado e ficava vazio até você visitar cada tela de novo — agora recarrega sozinho, na hora, se você já estiver logado; (2) o app confiava demais no aviso de "conectado" do celular (que engana quando tem wifi/dados sem internet de verdade) e podia travar no aviso de "reenviar formulário" — agora ele testa a conexão de verdade antes de tentar enviar.',
    ],
    [
        'versao'  => '1.6.6',
        'resumo'  => 'Correção do texto/elementos aparecendo grandes demais dentro do app instalado (mas normais no navegador): o Android tem um recurso de "aumentar a fonte sozinho pra ficar legível" que só age dentro do app, e ele estava sem essa trava.',
    ],
    [
        'versao'  => '1.6.5',
        'resumo'  => 'Correção definitiva do layout "de computador" no celular: a tentativa anterior (1.6.2) travava por "tem mouse de verdade?", mas em alguns aparelhos com caneta (S Pen, por exemplo) essa checagem dava falso positivo mesmo usando só o dedo. Agora a checagem é se a tela realmente tem toque — essa é a que nunca falha.',
    ],
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
