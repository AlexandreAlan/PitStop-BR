<?php
declare(strict_types=1);

/**
 * Roda periodicamente (ver serviço "cron" no docker-compose.yml) e manda um
 * push pra cada lembrete que ficou "vencido" ou "próximo" desde a última
 * checagem. Cada lembrete só gera UM push na vida dele (push_notificado_em
 * marca isso) — não reenvia a cada execução, e não reenvia de novo quando
 * "próximo" vira "vencido" (simplificação deliberada: um aviso já chama
 * atenção pro lembrete, e ele continua visível na lista/dashboard).
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/push.php';

$pdo = getConexao();

$stmt = $pdo->query(
    "SELECT l.id, l.descricao, l.tipo_alvo, l.km_alvo, l.data_alvo, v.usuario_id,
            (SELECT MAX(r.km_atual) FROM registros r WHERE r.veiculo_id = l.veiculo_id) AS km_atual_veiculo
     FROM lembretes l
     INNER JOIN veiculos v ON v.id = l.veiculo_id
     WHERE l.concluido_em IS NULL AND l.push_notificado_em IS NULL"
);
$lembretes = $stmt->fetchAll();

$enviados = 0;
foreach ($lembretes as $l) {
    $status = calcularStatusLembrete($l)['status'];
    if ($status === 'ok') {
        continue;
    }

    $titulo = $status === 'vencido' ? 'Lembrete vencido' : 'Lembrete se aproximando';
    enviarPushUsuario($pdo, (int) $l['usuario_id'], $titulo, $l['descricao'], '/lembretes.php');

    $marcar = $pdo->prepare('UPDATE lembretes SET push_notificado_em = NOW() WHERE id = :id');
    $marcar->execute([':id' => $l['id']]);
    $enviados++;
}

echo date('Y-m-d H:i:s') . " - lembretes verificados: " . count($lembretes) . ", push enviados: {$enviados}\n";
