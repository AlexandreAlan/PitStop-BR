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

$endpoint = (string) ($corpo['endpoint'] ?? '');
if ($endpoint === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Endpoint ausente.']);
    exit;
}

// Restrito ao próprio usuário — endpoint por si só não é segredo, mas nada
// aqui deve conseguir apagar a inscrição de outra conta.
$stmt = $pdo->prepare('DELETE FROM push_inscricoes WHERE usuario_id = :usuario_id AND endpoint_hash = :endpoint_hash');
$stmt->execute([':usuario_id' => $usuario['id'], ':endpoint_hash' => hash('sha256', $endpoint)]);

echo json_encode(['ok' => true]);
