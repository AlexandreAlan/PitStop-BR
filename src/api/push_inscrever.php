<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = usuarioAtual();
if ($usuario === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}

$corpo = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($corpo)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Corpo da requisição inválido.']);
    exit;
}

if (!csrfValidar($corpo['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Token CSRF ausente ou expirado.']);
    exit;
}

$inscricao = $corpo['inscricao'] ?? null;
$endpoint  = is_array($inscricao) ? (string) ($inscricao['endpoint'] ?? '') : '';
$p256dh    = is_array($inscricao) ? (string) ($inscricao['keys']['p256dh'] ?? '') : '';
$auth      = is_array($inscricao) ? (string) ($inscricao['keys']['auth'] ?? '') : '';

if ($endpoint === '' || $p256dh === '' || $auth === '' || mb_strlen($endpoint) > 500) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Inscrição de push inválida.']);
    exit;
}

$endpointHash = hash('sha256', $endpoint);

// Upsert: o mesmo endpoint pode já estar inscrito (usuário reabriu o app) —
// nesse caso só garante que continua vinculado à conta certa (troca de conta
// no mesmo navegador, por exemplo) e atualiza as chaves.
$stmt = $pdo->prepare(
    'INSERT INTO push_inscricoes (usuario_id, endpoint, endpoint_hash, p256dh, auth)
     VALUES (:usuario_id, :endpoint, :endpoint_hash, :p256dh, :auth)
     ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), p256dh = VALUES(p256dh), auth = VALUES(auth)'
);
$stmt->execute([
    ':usuario_id'    => $usuario['id'],
    ':endpoint'      => $endpoint,
    ':endpoint_hash' => $endpointHash,
    ':p256dh'        => $p256dh,
    ':auth'          => $auth,
]);

echo json_encode(['ok' => true]);
