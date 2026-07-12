<?php
declare(strict_types=1);

/**
 * Roda periodicamente (ver serviço "cron" no docker-compose.yml) e apaga
 * dados técnicos que já cumpriram sua função e não têm mais nenhum uso —
 * princípio de minimização/limitação de retenção da LGPD (Art. 6º, III).
 *
 * Escopo desta limpeza (só tabelas técnicas, nunca dado que o usuário vê
 * como histórico no app):
 * - *_rate_limit (login/cadastro/redefinicao/convite): só servem pra contar
 *   tentativas na última hora; nada além de 48h tem qualquer uso.
 * - verificacoes_email / redefinicoes_senha: tokens/códigos de uso único,
 *   sem tela nenhuma que liste o histórico deles.
 *
 * NÃO mexe em `convites` — aparece pro usuário em "Convites Enviados"
 * (convidar.php), então é histórico de verdade, não lixo técnico.
 */

require_once __DIR__ . '/../config/conexao.php';

$pdo = getConexao();

$RETENCAO_RATE_LIMIT_HORAS = 48;
$RETENCAO_TOKENS_DIAS = 2;

foreach (['login_rate_limit', 'cadastro_rate_limit', 'redefinicao_rate_limit', 'convite_rate_limit'] as $tabela) {
    $apagados = $pdo->exec(
        "DELETE FROM {$tabela} WHERE criado_em < (NOW() - INTERVAL {$RETENCAO_RATE_LIMIT_HORAS} HOUR)"
    );
    echo "{$tabela}: {$apagados} linha(s) apagada(s).\n";
}

$apagados = $pdo->exec(
    "DELETE FROM verificacoes_email WHERE criado_em < (NOW() - INTERVAL {$RETENCAO_TOKENS_DIAS} DAY)"
);
echo "verificacoes_email: {$apagados} linha(s) apagada(s).\n";

$apagados = $pdo->exec(
    "DELETE FROM redefinicoes_senha WHERE criado_em < (NOW() - INTERVAL {$RETENCAO_TOKENS_DIAS} DAY)"
);
echo "redefinicoes_senha: {$apagados} linha(s) apagada(s).\n";
