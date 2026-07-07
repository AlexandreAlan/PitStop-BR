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

/**
 * URL base do site pra montar links absolutos em e-mails (convite, reset de
 * senha). Prioriza a env APP_URL (fixa, definida no deploy) — nunca confia
 * em Host/X-Forwarded-Proto da requisição pra isso, senão um Host forjado
 * poderia colocar um domínio malicioso no link mandado pro usuário (host
 * header poisoning). Sem APP_URL configurada, cai pro Host da requisição
 * só como fallback de desenvolvimento local.
 */
function baseUrl(): string
{
    $appUrl = getenv('APP_URL');
    if ($appUrl !== false && $appUrl !== '') {
        return rtrim($appUrl, '/');
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($isHttps ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
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
 * Neutraliza CSV/Formula Injection: Excel, LibreOffice e Google Sheets tratam
 * células que começam com =, +, -, @, tab ou retorno de carro como fórmula —
 * um nome de veículo ou descrição de registro (texto livre do usuário) com
 * esse prefixo executaria a "fórmula" ao abrir o arquivo (ex.: chamando um
 * programa externo via DDE). Prefixar com aspas simples força texto puro.
 */
function sanitizarCelulaCsv(string $valor): string
{
    if ($valor !== '' && str_contains("=+-@\t\r", $valor[0])) {
        return "'" . $valor;
    }
    return $valor;
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

/**
 * Sequência (streak) de meses consecutivos com pelo menos 1 registro, e um
 * conjunto de conquistas por marco (abastecimentos registrados, consumo em
 * melhora no mês, manutenção em dia). Tudo calculado na hora a partir de
 * registros/lembretes existentes — não guarda estado próprio, então nunca
 * fica desatualizado.
 */
function calcularConquistas(PDO $pdo, int $usuarioId): array
{
    // Sequência de meses: conta pra trás a partir do mês atual (ou do último
    // mês com registro, se o mês corrente ainda estiver zerado — não
    // "quebra" a sequência só porque o mês acabou de começar).
    $mesesStmt = $pdo->prepare(
        "SELECT DISTINCT DATE_FORMAT(r.data, '%Y-%m') AS mes
         FROM registros r INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE v.usuario_id = :usuario_id"
    );
    $mesesStmt->execute([':usuario_id' => $usuarioId]);
    $mesesComRegistro = array_column($mesesStmt->fetchAll(), 'mes');

    $sequenciaMeses = 0;
    $cursor = new DateTime('first day of this month');
    if (!in_array($cursor->format('Y-m'), $mesesComRegistro, true)) {
        $cursor->modify('-1 month');
    }
    while (in_array($cursor->format('Y-m'), $mesesComRegistro, true)) {
        $sequenciaMeses++;
        $cursor->modify('-1 month');
    }

    // Total de abastecimentos — base dos marcos de quilometragem percorrida.
    $totalStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM registros r INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE v.usuario_id = :usuario_id AND r.tipo_registro = 'Abastecimento'"
    );
    $totalStmt->execute([':usuario_id' => $usuarioId]);
    $totalAbastecimentos = (int) $totalStmt->fetchColumn();

    // Consumo médio do mês atual x mês anterior (só entre abastecimentos
    // consecutivos do MESMO veículo) — melhora vira a conquista "Economia do Mês".
    $consumoStmt = $pdo->prepare(
        "SELECT mes, AVG((km_atual - km_anterior) / litros) AS consumo_medio FROM (
            SELECT DATE_FORMAT(r.data, '%Y-%m') AS mes, r.km_atual, r.litros,
                   LAG(r.km_atual) OVER (PARTITION BY r.veiculo_id ORDER BY r.km_atual) AS km_anterior
            FROM registros r INNER JOIN veiculos v ON v.id = r.veiculo_id
            WHERE v.usuario_id = :usuario_id
              AND r.tipo_registro = 'Abastecimento' AND r.litros IS NOT NULL
         ) t
         WHERE km_anterior IS NOT NULL AND km_atual > km_anterior
         GROUP BY mes ORDER BY mes DESC LIMIT 2"
    );
    $consumoStmt->execute([':usuario_id' => $usuarioId]);
    $consumoPorMes = $consumoStmt->fetchAll();
    $mesAtual = (new DateTime('today'))->format('Y-m');
    $economiaMes = count($consumoPorMes) === 2
        && $consumoPorMes[0]['mes'] === $mesAtual
        && (float) $consumoPorMes[0]['consumo_medio'] > (float) $consumoPorMes[1]['consumo_medio'];

    // Manutenção em dia: existe ao menos 1 lembrete ativo e nenhum vencido.
    $lembretesStmt = $pdo->prepare(
        "SELECT l.tipo_alvo, l.km_alvo, l.data_alvo,
                (SELECT MAX(r.km_atual) FROM registros r WHERE r.veiculo_id = l.veiculo_id) AS km_atual_veiculo
         FROM lembretes l INNER JOIN veiculos v ON v.id = l.veiculo_id
         WHERE v.usuario_id = :usuario_id AND l.concluido_em IS NULL"
    );
    $lembretesStmt->execute([':usuario_id' => $usuarioId]);
    $lembretesAtivos = $lembretesStmt->fetchAll();
    $temLembreteVencido = false;
    foreach ($lembretesAtivos as $l) {
        if (calcularStatusLembrete($l)['status'] === 'vencido') {
            $temLembreteVencido = true;
            break;
        }
    }
    $emDia = count($lembretesAtivos) > 0 && !$temLembreteVencido;

    $marcos = [1 => 'Primeira Carga', 10 => '10 Abastecimentos', 25 => '25 Abastecimentos',
               50 => '50 Abastecimentos', 100 => 'Motorista Veterano'];
    $proximoMarco = null;
    foreach ($marcos as $qtd => $titulo) {
        if ($totalAbastecimentos < $qtd) { $proximoMarco = ['qtd' => $qtd, 'titulo' => $titulo]; break; }
    }

    return [
        'sequenciaMeses' => $sequenciaMeses,
        'proximoMarco' => $proximoMarco,
        'totalAbastecimentos' => $totalAbastecimentos,
        'badges' => [
            ['codigo' => 'primeira_carga', 'titulo' => 'Primeira Carga', 'icone' => 'bi-fuel-pump-fill',
                'conquistada' => $totalAbastecimentos >= 1],
            ['codigo' => 'dez_abastecimentos', 'titulo' => '10 Abastecimentos', 'icone' => 'bi-award-fill',
                'conquistada' => $totalAbastecimentos >= 10],
            ['codigo' => 'cinquenta_abastecimentos', 'titulo' => 'Motorista Veterano', 'icone' => 'bi-trophy-fill',
                'conquistada' => $totalAbastecimentos >= 50],
            ['codigo' => 'economia_mes', 'titulo' => 'Economia do Mês', 'icone' => 'bi-graph-down-arrow',
                'conquistada' => $economiaMes],
            ['codigo' => 'em_dia', 'titulo' => 'Manutenção em Dia', 'icone' => 'bi-patch-check-fill',
                'conquistada' => $emDia],
        ],
    ];
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
 * Retorna ['id' => int, 'novo' => bool] — 'novo' distingue uma inserção real
 * de um replay idempotente, pra quem chama (ex.: detectarAnomaliasRegistro)
 * não reprocessar o mesmo registro a cada reenvio da fila offline.
 */
function inserirRegistro(PDO $pdo, array $valores, ?string $clientUuid = null): array
{
    if ($clientUuid !== null) {
        $existente = $pdo->prepare('SELECT id FROM registros WHERE client_uuid = :uuid');
        $existente->execute([':uuid' => $clientUuid]);
        $id = $existente->fetchColumn();
        if ($id !== false) {
            return ['id' => (int) $id, 'novo' => false];
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

    return ['id' => (int) $pdo->lastInsertId(), 'novo' => true];
}

const LIMIAR_QUEDA_CONSUMO = 0.20; // 20% pior que a média histórica do veículo
const LIMIAR_ALTA_PRECO    = 0.15; // 15% acima do preço/L médio histórico do veículo

/**
 * Insere um alerta pro usuário. Uso interno de detectarAnomaliasRegistro().
 */
function inserirAlerta(PDO $pdo, int $usuarioId, int $veiculoId, ?int $registroId, string $tipo, string $severidade, string $titulo, string $mensagem): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO alertas (usuario_id, veiculo_id, tipo, severidade, titulo, mensagem, registro_id)
         VALUES (:usuario_id, :veiculo_id, :tipo, :severidade, :titulo, :mensagem, :registro_id)'
    );
    $stmt->execute([
        ':usuario_id'  => $usuarioId,
        ':veiculo_id'  => $veiculoId,
        ':tipo'        => $tipo,
        ':severidade'  => $severidade,
        ':titulo'      => $titulo,
        ':mensagem'    => $mensagem,
        ':registro_id' => $registroId,
    ]);
}

/**
 * Detecta anomalias no registro recém-inserido (odômetro fora de ordem,
 * consumo em queda, preço acima do normal) e grava um alerta pra cada uma
 * encontrada. Chamar logo depois de inserirRegistro(). Nunca lança exceção:
 * é uma verificação best-effort e um erro aqui não pode impedir o registro
 * em si — só loga e segue.
 */
function detectarAnomaliasRegistro(PDO $pdo, int $usuarioId, array $valores, int $registroId): void
{
    try {
        $veiculoId = (int) $valores['veiculo_id'];
        $kmAtual   = (int) $valores['km_atual'];

        // Odômetro fora de ordem: km menor ou igual ao maior já registrado
        // pro veículo (em qualquer tipo de registro) — sugere erro de
        // digitação ou lançamento fora de ordem.
        $stmt = $pdo->prepare(
            'SELECT MAX(km_atual) FROM registros WHERE veiculo_id = :veiculo_id AND id != :registro_id'
        );
        $stmt->execute([':veiculo_id' => $veiculoId, ':registro_id' => $registroId]);
        $kmAnteriorMax = $stmt->fetchColumn();
        if ($kmAnteriorMax !== false && $kmAnteriorMax !== null && $kmAtual <= (int) $kmAnteriorMax) {
            inserirAlerta(
                $pdo, $usuarioId, $veiculoId, $registroId, 'odometro_inconsistente', 'atencao',
                'Odômetro fora de ordem',
                "KM {$kmAtual} informado é menor ou igual ao último já registrado ({$kmAnteriorMax}). Confira o valor."
            );
        }

        if ($valores['tipo_registro'] !== 'Abastecimento' || !$valores['litros']) {
            return;
        }

        $litros    = (float) $valores['litros'];
        $valorPago = (float) $valores['valor_pago'];

        // Consumo em queda: compara o km/l do trecho recém-fechado (o mais
        // recente, por km_atual) com a média dos trechos anteriores do
        // mesmo veículo. Exige pelo menos 3 trechos no total pra ter uma
        // linha de base minimamente confiável antes de comparar.
        $stmt = $pdo->prepare(
            "SELECT km_atual, litros, LAG(km_atual) OVER (ORDER BY km_atual) AS km_anterior
             FROM registros
             WHERE veiculo_id = :veiculo_id AND tipo_registro = 'Abastecimento' AND litros IS NOT NULL
             ORDER BY km_atual"
        );
        $stmt->execute([':veiculo_id' => $veiculoId]);
        $trechos = [];
        foreach ($stmt->fetchAll() as $linha) {
            if ($linha['km_anterior'] === null) {
                continue;
            }
            $kmTrecho     = (int) $linha['km_atual'] - (int) $linha['km_anterior'];
            $litrosTrecho = (float) $linha['litros'];
            if ($kmTrecho > 0 && $litrosTrecho > 0) {
                $trechos[] = $kmTrecho / $litrosTrecho;
            }
        }
        if (count($trechos) >= 3) {
            $trechoAtual   = array_pop($trechos);
            $mediaAnterior = array_sum($trechos) / count($trechos);
            if ($mediaAnterior > 0 && $trechoAtual < $mediaAnterior * (1 - LIMIAR_QUEDA_CONSUMO)) {
                $percentual = (int) round((1 - $trechoAtual / $mediaAnterior) * 100);
                inserirAlerta(
                    $pdo, $usuarioId, $veiculoId, $registroId, 'consumo_baixo', 'atencao',
                    'Consumo abaixo do normal',
                    "Este abastecimento rendeu {$percentual}% menos que a média do veículo. Pode valer uma revisão."
                );
            }
        }

        // Preço acima do normal: compara o preço/L pago com a média dos
        // outros abastecimentos do mesmo veículo. Exige pelo menos 2
        // abastecimentos anteriores pra ter uma média minimamente estável.
        $stmt = $pdo->prepare(
            "SELECT valor_pago, litros FROM registros
             WHERE veiculo_id = :veiculo_id AND tipo_registro = 'Abastecimento' AND litros IS NOT NULL
               AND id != :registro_id"
        );
        $stmt->execute([':veiculo_id' => $veiculoId, ':registro_id' => $registroId]);
        $precos = [];
        foreach ($stmt->fetchAll() as $linha) {
            $l = (float) $linha['litros'];
            if ($l > 0) {
                $precos[] = (float) $linha['valor_pago'] / $l;
            }
        }
        if (count($precos) >= 2) {
            $precoAtual = $valorPago / $litros;
            $precoMedio = array_sum($precos) / count($precos);
            if ($precoMedio > 0 && $precoAtual > $precoMedio * (1 + LIMIAR_ALTA_PRECO)) {
                $percentual = (int) round(($precoAtual / $precoMedio - 1) * 100);
                inserirAlerta(
                    $pdo, $usuarioId, $veiculoId, $registroId, 'preco_alto', 'info',
                    'Preço acima da média',
                    "Preço pago {$percentual}% acima da média histórica desse veículo (" . formatarMoeda($precoAtual) . "/L)."
                );
            }
        }
    } catch (Throwable $e) {
        error_log('detectarAnomaliasRegistro: ' . $e->getMessage());
    }
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
