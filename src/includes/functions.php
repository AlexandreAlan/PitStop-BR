<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * IP real do visitante. Confiável aqui porque o container web só escuta em
 * 127.0.0.1 (ver docker-compose.yml) — só o nginx do host consegue falar
 * com ele, então X-Real-IP sempre veio do proxy, nunca de um cliente externo.
 */
function clienteIp(): string
{
    return (string) ($_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
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
 * Estatísticas de um veículo específico (gasto, km rodado, custo por km e
 * consumo médio), respeitando um recorte opcional de período — usado na
 * comparação entre veículos em relatorios.php. Sempre restrito aos veículos
 * do usuário informado (o próprio ID do veículo já garante o filtro).
 */
function calcularEstatisticasVeiculo(PDO $pdo, int $usuarioId, int $veiculoId, ?string $dataInicio, ?string $dataFim): array
{
    $filtroData = ($dataInicio !== null ? ' AND r.data >= :data_inicio' : '')
        . ($dataFim !== null ? ' AND r.data <= :data_fim' : '');

    $bind = function (PDOStatement $stmt) use ($usuarioId, $veiculoId, $dataInicio, $dataFim): void {
        $stmt->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':veiculo_id', $veiculoId, PDO::PARAM_INT);
        if ($dataInicio !== null) {
            $stmt->bindValue(':data_inicio', $dataInicio);
        }
        if ($dataFim !== null) {
            $stmt->bindValue(':data_fim', $dataFim);
        }
    };

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(r.valor_pago), 0) FROM registros r
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE v.usuario_id = :usuario_id AND v.id = :veiculo_id' . $filtroData
    );
    $bind($stmt);
    $stmt->execute();
    $gasto = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(GREATEST(km_atual - km_anterior, 0)), 0) FROM (
            SELECT r.km_atual, LAG(r.km_atual) OVER (ORDER BY r.km_atual) AS km_anterior
            FROM registros r
            INNER JOIN veiculos v ON v.id = r.veiculo_id
            WHERE v.usuario_id = :usuario_id AND v.id = :veiculo_id' . $filtroData . '
         ) t WHERE km_anterior IS NOT NULL'
    );
    $bind($stmt);
    $stmt->execute();
    $kmRodado = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT km_atual, litros, km_anterior FROM (
            SELECT r.km_atual, r.litros, LAG(r.km_atual) OVER (ORDER BY r.km_atual) AS km_anterior
            FROM registros r
            INNER JOIN veiculos v ON v.id = r.veiculo_id
            WHERE v.usuario_id = :usuario_id AND v.id = :veiculo_id
              AND r.tipo_registro = "Abastecimento" AND r.litros IS NOT NULL' . $filtroData . '
         ) t WHERE km_anterior IS NOT NULL'
    );
    $bind($stmt);
    $stmt->execute();
    $consumos = [];
    foreach ($stmt->fetchAll() as $linha) {
        $kmTrecho = (int) $linha['km_atual'] - (int) $linha['km_anterior'];
        $litros   = (float) $linha['litros'];
        if ($kmTrecho > 0 && $litros > 0) {
            $consumos[] = $kmTrecho / $litros;
        }
    }

    return [
        'gasto'         => $gasto,
        'km_rodado'     => $kmRodado,
        'custo_km'      => $kmRodado > 0 ? $gasto / $kmRodado : null,
        'consumo_medio' => $consumos ? round(array_sum($consumos) / count($consumos), 1) : null,
    ];
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

const COMBUSTIVEIS_PERMITIDOS = ['Gasolina Comum', 'Gasolina Aditivada', 'Etanol', 'Diesel', 'GNV', 'Outro'];
const CATEGORIAS_DESPESA_PERMITIDAS = ['Seguro', 'IPVA', 'Estacionamento', 'Pedagio', 'Multa', 'Lavagem', 'Outro'];

/**
 * Valida e normaliza os dados de um registro (usado tanto pelo formulário
 * clássico em adicionar.php quanto pela API usada pela fila offline).
 * Retorna ['ok' => bool, 'erros' => [...], 'valores' => [...prontos pro INSERT...]].
 */
function validarRegistro(PDO $pdo, int $usuarioId, array $dados): array
{
    $erros = [];

    $veiculoId        = filter_var($dados['veiculo_id'] ?? '', FILTER_VALIDATE_INT);
    $dataStr          = (string) ($dados['data'] ?? '');
    $kmAtual          = filter_var($dados['km_atual'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $tipoRegistro     = in_array($dados['tipo_registro'] ?? '', ['Abastecimento', 'Manutencao', 'Despesa'], true) ? $dados['tipo_registro'] : null;
    $valorPago        = filter_var($dados['valor_pago'] ?? '', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
    $litrosStr        = (string) ($dados['litros'] ?? '');
    $litros           = $litrosStr === '' ? null : filter_var($litrosStr, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.01]]);
    $combustivel      = in_array($dados['combustivel'] ?? '', COMBUSTIVEIS_PERMITIDOS, true) ? $dados['combustivel'] : null;
    $categoriaDespesa = in_array($dados['categoria_despesa'] ?? '', CATEGORIAS_DESPESA_PERMITIDAS, true) ? $dados['categoria_despesa'] : null;
    $descricao        = trim((string) ($dados['descricao'] ?? ''));
    $dataRegistro     = DateTime::createFromFormat('Y-m-d', $dataStr);

    if (!$veiculoId) {
        $erros[] = 'Selecione um veículo válido.';
    } else {
        $existe = $pdo->prepare('SELECT 1 FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
        $existe->execute([':id' => $veiculoId, ':usuario_id' => $usuarioId]);
        if (!$existe->fetchColumn()) {
            $erros[] = 'Veículo não encontrado.';
        }
    }
    if (!$dataRegistro || $dataRegistro->format('Y-m-d') !== $dataStr) {
        $erros[] = 'Data inválida.';
    }
    if ($kmAtual === false || $kmAtual === null) {
        $erros[] = 'KM atual inválido.';
    }
    if (!$tipoRegistro) {
        $erros[] = 'Tipo de registro inválido.';
    }
    if ($valorPago === false || $valorPago === null) {
        $erros[] = 'Valor pago inválido.';
    }
    if ($tipoRegistro === 'Abastecimento' && !$litros) {
        $erros[] = 'Informe os litros abastecidos.';
    }
    if ($tipoRegistro === 'Abastecimento' && !$combustivel) {
        $erros[] = 'Selecione o combustível.';
    }
    if ($tipoRegistro === 'Despesa' && !$categoriaDespesa) {
        $erros[] = 'Selecione a categoria da despesa.';
    }
    if (mb_strlen($descricao) > 255) {
        $erros[] = 'Descrição muito longa (máx. 255 caracteres).';
    }

    if ($erros) {
        return ['ok' => false, 'erros' => $erros, 'valores' => $dados];
    }

    return [
        'ok'      => true,
        'erros'   => [],
        'valores' => [
            'veiculo_id'        => $veiculoId,
            'data'              => $dataRegistro->format('Y-m-d'),
            'km_atual'          => $kmAtual,
            'tipo_registro'     => $tipoRegistro,
            'combustivel'       => $tipoRegistro === 'Abastecimento' ? $combustivel : null,
            'litros'            => $tipoRegistro === 'Abastecimento' ? $litros : null,
            'categoria_despesa' => $tipoRegistro === 'Despesa' ? $categoriaDespesa : null,
            'valor_pago'        => $valorPago,
            'descricao'         => $descricao !== '' ? $descricao : null,
        ],
    ];
}

/**
 * Insere um registro já validado (ver validarRegistro). $clientUuid, quando
 * informado, torna a inserção idempotente — reenvios da fila offline com o
 * mesmo uuid não duplicam a linha (ver UNIQUE KEY uq_registros_client_uuid).
 */
function inserirRegistro(PDO $pdo, array $valores, ?string $clientUuid = null): int
{
    if ($clientUuid !== null) {
        $existente = $pdo->prepare('SELECT id FROM registros WHERE client_uuid = :uuid');
        $existente->execute([':uuid' => $clientUuid]);
        $id = $existente->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO registros (veiculo_id, data, km_atual, tipo_registro, combustivel, litros, categoria_despesa, valor_pago, descricao, client_uuid)
         VALUES (:veiculo_id, :data, :km_atual, :tipo_registro, :combustivel, :litros, :categoria_despesa, :valor_pago, :descricao, :client_uuid)'
    );
    $stmt->execute([
        ':veiculo_id'        => $valores['veiculo_id'],
        ':data'              => $valores['data'],
        ':km_atual'          => $valores['km_atual'],
        ':tipo_registro'     => $valores['tipo_registro'],
        ':combustivel'       => $valores['combustivel'],
        ':litros'            => $valores['litros'],
        ':categoria_despesa' => $valores['categoria_despesa'],
        ':valor_pago'        => $valores['valor_pago'],
        ':descricao'         => $valores['descricao'],
        ':client_uuid'       => $clientUuid,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Valida e normaliza os dados de um lembrete (formulário clássico e API offline).
 */
function validarLembrete(PDO $pdo, int $usuarioId, array $dados): array
{
    $erros = [];

    $veiculoId  = filter_var($dados['veiculo_id'] ?? '', FILTER_VALIDATE_INT);
    $descricao  = trim((string) ($dados['descricao'] ?? ''));
    $tipoAlvo   = in_array($dados['tipo_alvo'] ?? '', ['KM', 'Data'], true) ? $dados['tipo_alvo'] : null;
    $kmAlvoStr  = (string) ($dados['km_alvo'] ?? '');
    $kmAlvo     = $kmAlvoStr === '' ? null : filter_var($kmAlvoStr, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $dataAlvoStr = (string) ($dados['data_alvo'] ?? '');
    $dataAlvo   = $dataAlvoStr === '' ? null : DateTime::createFromFormat('Y-m-d', $dataAlvoStr);

    if (!$veiculoId) {
        $erros[] = 'Selecione um veículo válido.';
    } else {
        $existe = $pdo->prepare('SELECT 1 FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
        $existe->execute([':id' => $veiculoId, ':usuario_id' => $usuarioId]);
        if (!$existe->fetchColumn()) {
            $erros[] = 'Veículo não encontrado.';
        }
    }
    if ($descricao === '' || mb_strlen($descricao) > 150) {
        $erros[] = 'Descrição inválida (máx. 150 caracteres).';
    }
    if (!$tipoAlvo) {
        $erros[] = 'Selecione se o lembrete é por km ou por data.';
    }
    if ($tipoAlvo === 'KM' && !$kmAlvo) {
        $erros[] = 'Informe o km alvo do lembrete.';
    }
    if ($tipoAlvo === 'Data' && (!$dataAlvo || $dataAlvo->format('Y-m-d') !== $dataAlvoStr)) {
        $erros[] = 'Informe uma data alvo válida.';
    }

    if ($erros) {
        return ['ok' => false, 'erros' => $erros, 'valores' => $dados];
    }

    return [
        'ok'      => true,
        'erros'   => [],
        'valores' => [
            'veiculo_id' => $veiculoId,
            'descricao'  => $descricao,
            'tipo_alvo'  => $tipoAlvo,
            'km_alvo'    => $tipoAlvo === 'KM' ? $kmAlvo : null,
            'data_alvo'  => $tipoAlvo === 'Data' ? $dataAlvo->format('Y-m-d') : null,
        ],
    ];
}

/** Insere um lembrete já validado (ver validarLembrete); idempotente via client_uuid. */
function inserirLembrete(PDO $pdo, array $valores, ?string $clientUuid = null): int
{
    if ($clientUuid !== null) {
        $existente = $pdo->prepare('SELECT id FROM lembretes WHERE client_uuid = :uuid');
        $existente->execute([':uuid' => $clientUuid]);
        $id = $existente->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO lembretes (veiculo_id, descricao, tipo_alvo, km_alvo, data_alvo, client_uuid)
         VALUES (:veiculo_id, :descricao, :tipo_alvo, :km_alvo, :data_alvo, :client_uuid)'
    );
    $stmt->execute([
        ':veiculo_id' => $valores['veiculo_id'],
        ':descricao'  => $valores['descricao'],
        ':tipo_alvo'  => $valores['tipo_alvo'],
        ':km_alvo'    => $valores['km_alvo'],
        ':data_alvo'  => $valores['data_alvo'],
        ':client_uuid' => $clientUuid,
    ]);

    return (int) $pdo->lastInsertId();
}
