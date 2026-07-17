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

$resultado = validarRegistro($pdo, $usuario['id'], $corpo);
if (!$resultado['ok']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erros' => $resultado['erros']]);
    exit;
}

$inserido = inserirRegistro($pdo, $resultado['valores'], $clientUuid);
if ($inserido['novo']) {
    detectarAnomaliasRegistro($pdo, $usuario['id'], $resultado['valores'], $inserido['id']);
}

// Foto de comprovante: mesmo campo (foto_base64) do formulário clássico,
// só que aqui chega via JSON — funciona tanto no envio direto quanto no
// replay da fila offline (ver assets/js/idb-outbox.js), sem endpoint à
// parte. Falha na foto não derruba o registro, que já foi salvo.
$avisoFoto = null;
$fotoBase64 = isset($corpo['foto_base64']) ? (string) $corpo['foto_base64'] : '';
if ($fotoBase64 !== '') {
    $resultadoFoto = salvarFotoRegistro($pdo, $usuario['id'], $inserido['id'], $fotoBase64);
    if (!$resultadoFoto['ok']) {
        $avisoFoto = $resultadoFoto['erro'];
    }
}

echo json_encode(['ok' => true, 'id' => $inserido['id'], 'aviso_foto' => $avisoFoto]);
