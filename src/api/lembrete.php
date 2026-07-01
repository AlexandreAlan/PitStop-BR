<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = usuarioAtual();
if ($usuario === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada. Entre novamente pra sincronizar.']);
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

$clientUuid = isset($corpo['client_uuid']) ? (string) $corpo['client_uuid'] : null;
if ($clientUuid !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientUuid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'client_uuid inválido.']);
    exit;
}

$resultado = validarLembrete($pdo, $usuario['id'], $corpo);
if (!$resultado['ok']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erros' => $resultado['erros']]);
    exit;
}

$id = inserirLembrete($pdo, $resultado['valores'], $clientUuid);
echo json_encode(['ok' => true, 'id' => $id]);
