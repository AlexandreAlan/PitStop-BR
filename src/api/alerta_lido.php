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

$id = filter_var($corpo['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'ID inválido.']);
    exit;
}

// Restrito ao próprio usuário — alertas guardam usuario_id diretamente.
$stmt = $pdo->prepare(
    'UPDATE alertas SET lido_em = NOW() WHERE id = :id AND usuario_id = :usuario_id AND lido_em IS NULL'
);
$stmt->execute([':id' => $id, ':usuario_id' => $usuario['id']]);

echo json_encode(['ok' => true]);
