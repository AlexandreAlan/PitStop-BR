<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flashSet(string $tipo, string $mensagem): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function flashPegar(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function formatarMoeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

/**
 * KM/L calculado a partir dos dois últimos abastecimentos (por KM), não por data.
 * Sempre restrito aos veículos do usuário informado.
 */
function calcularUltimaMedia(PDO $pdo, int $usuarioId, ?int $veiculoId = null): ?float
{
    $sql = "SELECT r.km_atual, r.litros FROM registros r
            INNER JOIN veiculos v ON v.id = r.veiculo_id
            WHERE v.usuario_id = :usuario_id
              AND r.tipo_registro = 'Abastecimento' AND r.litros IS NOT NULL"
        . ($veiculoId !== null ? ' AND r.veiculo_id = :veiculo_id' : '')
        . ' ORDER BY r.km_atual DESC LIMIT 2';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
    if ($veiculoId !== null) {
        $stmt->bindValue(':veiculo_id', $veiculoId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $linhas = $stmt->fetchAll();

    if (count($linhas) < 2) {
        return null;
    }

    $kmRodado = (int) $linhas[0]['km_atual'] - (int) $linhas[1]['km_atual'];
    $litros   = (float) $linhas[0]['litros'];

    if ($kmRodado <= 0 || $litros <= 0) {
        return null;
    }

    return round($kmRodado / $litros, 1);
}

/**
 * Status de um lembrete de manutenção: 'vencido', 'proximo' ou 'ok'.
 * Lembrete por KM usa o km_atual_veiculo (maior km_atual já registrado pro
 * veículo); sem nenhum registro ainda, considera "ok" (não dá pra saber).
 */
function calcularStatusLembrete(array $lembrete): array
{
    $rotulos = [
        'vencido' => ['Vencido', 'bg-danger'],
        'proximo' => ['Próximo', 'bg-warning text-dark'],
        'ok'      => ['Em dia', 'bg-success'],
    ];

    if ($lembrete['tipo_alvo'] === 'Data') {
        $hoje = new DateTime('today');
        $alvo = new DateTime((string) $lembrete['data_alvo']);
        $dias = (int) $hoje->diff($alvo)->format('%r%a');
        $status = $dias < 0 ? 'vencido' : ($dias <= 15 ? 'proximo' : 'ok');
    } else {
        $atual = $lembrete['km_atual_veiculo'] !== null ? (int) $lembrete['km_atual_veiculo'] : null;
        if ($atual === null) {
            $status = 'ok';
        } else {
            $restante = (int) $lembrete['km_alvo'] - $atual;
            $status = $restante <= 0 ? 'vencido' : ($restante <= 500 ? 'proximo' : 'ok');
        }
    }

    return ['status' => $status, 'rotulo' => $rotulos[$status][0], 'classe' => $rotulos[$status][1]];
}
