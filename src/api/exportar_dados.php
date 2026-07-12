<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

/**
 * Portabilidade de dados (LGPD Art. 18, V) — baixa uma cópia estruturada
 * (JSON) de tudo que o usuário cadastrou: perfil, veículos, registros
 * (abastecimento/manutenção/despesa) e lembretes. Nunca inclui senha_hash
 * nem qualquer outro dado de segurança (tokens, hashes de rate limit etc.)
 * — só o que o próprio usuário cadastrou/vê no app.
 */

// Não usa exigirLogin() aqui: o redirect dela ('Location: login.php') é
// relativo e, chamado de dentro de /api/, resolveria pra /api/login.php
// (404) em vez de /login.php.
$usuario = usuarioAtual();
if ($usuario === null) {
    header('Location: /login.php');
    exit;
}

$perfilStmt = $pdo->prepare(
    'SELECT nome, email, criado_em, aceite_privacidade_em, email_verificado_em, meta_mensal
     FROM usuarios WHERE id = :id'
);
$perfilStmt->execute([':id' => $usuario['id']]);
$perfil = $perfilStmt->fetch();

$veiculosStmt = $pdo->prepare(
    'SELECT id, nome, tipo, cor, placa, tanque_litros, peso_kg, criado_em
     FROM veiculos WHERE usuario_id = :usuario_id ORDER BY id'
);
$veiculosStmt->execute([':usuario_id' => $usuario['id']]);
$veiculos = $veiculosStmt->fetchAll();

$registrosStmt = $pdo->prepare(
    'SELECT r.id, r.veiculo_id, r.data, r.km_atual, r.tipo_registro, r.combustivel, r.litros,
            r.tanque_cheio, r.categoria_despesa, r.valor_pago, r.descricao, r.criado_em
     FROM registros r INNER JOIN veiculos v ON v.id = r.veiculo_id
     WHERE v.usuario_id = :usuario_id ORDER BY r.id'
);
$registrosStmt->execute([':usuario_id' => $usuario['id']]);
$registros = $registrosStmt->fetchAll();

$lembretesStmt = $pdo->prepare(
    'SELECT l.id, l.veiculo_id, l.descricao, l.tipo_alvo, l.km_alvo, l.data_alvo, l.concluido_em, l.criado_em
     FROM lembretes l INNER JOIN veiculos v ON v.id = l.veiculo_id
     WHERE v.usuario_id = :usuario_id ORDER BY l.id'
);
$lembretesStmt->execute([':usuario_id' => $usuario['id']]);
$lembretes = $lembretesStmt->fetchAll();

$exportacao = [
    'gerado_em'  => (new DateTime())->format(DateTime::ATOM),
    'perfil'     => $perfil,
    'veiculos'   => $veiculos,
    'registros'  => $registros,
    'lembretes'  => $lembretes,
];

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="pitstop-meus-dados-' . date('Y-m-d') . '.json"');
echo json_encode($exportacao, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
