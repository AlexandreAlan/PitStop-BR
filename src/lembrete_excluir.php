<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Método não permitido.');
}

csrfVerificarOuFalhar();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    die('ID inválido.');
}

$stmt = $pdo->prepare(
    'DELETE l FROM lembretes l
     INNER JOIN veiculos v ON v.id = l.veiculo_id
     WHERE l.id = :id AND v.usuario_id = :usuario_id'
);
$stmt->execute([':id' => $id, ':usuario_id' => $usuario['id']]);

if ($stmt->rowCount() === 0) {
    flashSet('erro', 'Lembrete não encontrado.');
} else {
    flashSet('sucesso', 'Lembrete excluído.');
}
header('Location: lembretes.php');
exit;
