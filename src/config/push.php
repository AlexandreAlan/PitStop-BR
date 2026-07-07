<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Monta o cliente Web Push a partir das chaves VAPID do ambiente. Sem elas
 * configuradas (dev sem push), retorna null — quem chama simplesmente não
 * envia nada, sem quebrar o resto do app.
 */
function obterWebPush(): ?WebPush
{
    $public  = (string) (getenv('VAPID_PUBLIC_KEY') ?: '');
    $private = (string) (getenv('VAPID_PRIVATE_KEY') ?: '');
    if ($public === '' || $private === '') {
        return null;
    }

    return new WebPush([
        'VAPID' => [
            'subject'    => (string) (getenv('VAPID_SUBJECT') ?: 'mailto:contato@pitstop.morenadoaco.com.br'),
            'publicKey'  => $public,
            'privateKey' => $private,
        ],
    ]);
}

/**
 * Envia uma notificação push pra todas as inscrições ativas de um usuário
 * (pode ter mais de um aparelho/navegador inscrito). Qualquer inscrição que
 * o próprio push service reportar como expirada/inválida é apagada na hora
 * — evita acumular lixo na tabela sem precisar de rotina de limpeza à parte.
 */
function enviarPushUsuario(PDO $pdo, int $usuarioId, string $titulo, string $corpo, string $url = '/lembretes.php'): void
{
    $webPush = obterWebPush();
    if ($webPush === null) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM push_inscricoes WHERE usuario_id = :usuario_id');
    $stmt->execute([':usuario_id' => $usuarioId]);
    $inscricoes = $stmt->fetchAll();
    if (!$inscricoes) {
        return;
    }

    $payload = json_encode(['titulo' => $titulo, 'corpo' => $corpo, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $idPorEndpoint = array_column($inscricoes, 'id', 'endpoint');

    foreach ($inscricoes as $insc) {
        $webPush->queueNotification(
            Subscription::create([
                'endpoint'   => $insc['endpoint'],
                'publicKey'  => $insc['p256dh'],
                'authToken'  => $insc['auth'],
            ]),
            $payload
        );
    }

    foreach ($webPush->flush() as $relatorio) {
        if ($relatorio->isSuccess()) {
            continue;
        }
        if ($relatorio->isSubscriptionExpired() && isset($idPorEndpoint[$relatorio->getEndpoint()])) {
            $del = $pdo->prepare('DELETE FROM push_inscricoes WHERE id = :id');
            $del->execute([':id' => $idPorEndpoint[$relatorio->getEndpoint()]]);
        } else {
            error_log('[PitStop BR] Push falhou (' . $relatorio->getEndpoint() . '): ' . $relatorio->getReason());
        }
    }
}
