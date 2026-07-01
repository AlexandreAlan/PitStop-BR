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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}

$consulta = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($consulta) < 2) {
    echo json_encode(['ok' => true, 'resultados' => []]);
    exit;
}

// Separa um ano (4 dígitos, 19xx/20xx) das palavras de marca/modelo — assim
// "bros 160 2025" busca por texto E filtra pelo ano na mesma tacada.
$palavras = preg_split('/\s+/', $consulta, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$ano = null;
$termosTexto = [];
foreach ($palavras as $palavra) {
    if ($ano === null && preg_match('/^(19|20)\d{2}$/', $palavra)) {
        $ano = (int) $palavra;
        continue;
    }
    $termosTexto[] = $palavra;
}

$sql = 'SELECT id, tipo, marca, modelo, ano_inicio, ano_fim, tanque_litros, peso_kg, consumo_cidade_kml, consumo_estrada_kml
        FROM modelos_veiculos WHERE 1=1';
$params = [];

foreach ($termosTexto as $i => $termo) {
    $sql .= " AND CONCAT(marca, ' ', modelo) LIKE :termo{$i}";
    $params[":termo{$i}"] = '%' . $termo . '%';
}

if ($ano !== null) {
    // EMULATE_PREPARES está desligado (prepared statement nativo) — não dá
    // pra reusar o mesmo :placeholder duas vezes com um valor só, precisa
    // de um nome por ocorrência.
    $sql .= ' AND ano_inicio <= :ano_max AND (ano_fim IS NULL OR ano_fim >= :ano_min)';
    $params[':ano_max'] = $ano;
    $params[':ano_min'] = $ano;
}

$sql .= ' ORDER BY marca, modelo, ano_inicio DESC LIMIT 8';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$resultados = $stmt->fetchAll();

echo json_encode(['ok' => true, 'resultados' => $resultados]);
