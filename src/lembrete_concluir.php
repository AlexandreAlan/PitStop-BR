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
    'UPDATE lembretes l
     INNER JOIN veiculos v ON v.id = l.veiculo_id
     SET l.concluido_em = NOW()
     WHERE l.id = :id AND v.usuario_id = :usuario_id AND l.concluido_em IS NULL'
);
$stmt->execute([':id' => $id, ':usuario_id' => $usuario['id']]);

if ($stmt->rowCount() === 0) {
    flashSet('erro', 'Lembrete não encontrado.');
} else {
    flashSet('sucesso', 'Lembrete marcado como feito.');
}
header('Location: lembretes.php');
exit;
