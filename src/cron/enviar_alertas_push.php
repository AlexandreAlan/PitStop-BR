<?php
declare(strict_types=1);

/**
 * Roda periodicamente (ver serviço "cron" no docker-compose.yml) e manda um
 * push pra cada alerta inteligente ainda não notificado (detectado em
 * detectarAnomaliasRegistro(), ver includes/functions.php). Cada alerta só
 * gera UM push na vida dele (push_notificado_em marca isso), mesmo padrão de
 * cron/enviar_lembretes_push.php.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/push.php';

$pdo = getConexao();

$stmt = $pdo->query(
    "SELECT id, usuario_id, titulo, mensagem
     FROM alertas
     WHERE push_notificado_em IS NULL"
);
$alertas = $stmt->fetchAll();

$enviados = 0;
foreach ($alertas as $a) {
    enviarPushUsuario($pdo, (int) $a['usuario_id'], $a['titulo'], $a['mensagem'], '/index.php');

    $marcar = $pdo->prepare('UPDATE alertas SET push_notificado_em = NOW() WHERE id = :id');
    $marcar->execute([':id' => $a['id']]);
    $enviados++;
}

echo date('Y-m-d H:i:s') . " - alertas verificados: " . count($alertas) . ", push enviados: {$enviados}\n";
