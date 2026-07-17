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
 * Trechos de consumo (km/l) de um veículo, respeitando tanque_cheio.
 *
 * O km/l só é confiável entre dois pontos de TANQUE CHEIO confirmado: é o
 * único jeito de saber quanto combustível foi realmente queimado num
 * intervalo de km. Um abastecimento parcial (tanque_cheio = 0) não fecha
 * trecho sozinho — seus litros ficam "acumulados" até o próximo tanque
 * cheio, quando então o trecho inteiro (km rodado ÷ litros acumulados) é
 * calculado de uma vez. Sem isso, um complemento pequeno logo após rodar
 * muitos km gera um km/l absurdo (ex.: 4,49L depois de 310km → 69 km/l).
 *
 * Sempre restrito aos veículos do usuário informado. Retorna a lista de
 * trechos fechados, ordenados por km_atual crescente.
 *
 * @return array<int, array{data: string, km_atual: int, km_trecho: int, litros: float, consumo: float}>
 */
function calcularTrechosConsumo(PDO $pdo, int $usuarioId, int $veiculoId, ?string $dataInicio = null, ?string $dataFim = null): array
{
    $filtroData = ($dataInicio !== null ? ' AND r.data >= :data_inicio' : '')
        . ($dataFim !== null ? ' AND r.data <= :data_fim' : '');

    $stmt = $pdo->prepare(
        "SELECT r.data, r.km_atual, r.litros, r.tanque_cheio FROM registros r
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE " . condicaoAcessoVeiculo('v') . " AND v.id = :veiculo_id
           AND r.tipo_registro = 'Abastecimento' AND r.litros IS NOT NULL" . $filtroData . '
         ORDER BY r.km_atual ASC'
    );
    bindAcessoVeiculo($stmt, $usuarioId);
    $stmt->bindValue(':veiculo_id', $veiculoId, PDO::PARAM_INT);
    if ($dataInicio !== null) {
        $stmt->bindValue(':data_inicio', $dataInicio);
    }
    if ($dataFim !== null) {
        $stmt->bindValue(':data_fim', $dataFim);
    }
    $stmt->execute();

    return acumularTrechosConsumo($stmt->fetchAll());
}

/**
 * Lógica pura de fechamento de trechos (extraída de calcularTrechosConsumo
 * pra ser reaproveitada por calcularTrechosConsumoVeiculo(), que consulta
 * SEM o filtro de dono/colaborador — ver o porquê dessa exceção deliberada
 * na doc dela). Mesmo comportamento, ordem e regras, só sem a query embutida.
 *
 * @param array<int, array{data: string, km_atual: int|string, litros: float|string, tanque_cheio: int|string}> $linhas ordenadas por km_atual ASC
 */
function acumularTrechosConsumo(array $linhas): array
{
    $trechos          = [];
    $kmInicioTrecho    = null;
    $litrosAcumulados = 0.0;

    foreach ($linhas as $linha) {
        $kmAtual     = (int) $linha['km_atual'];
        $litros      = (float) $linha['litros'];
        $tanqueCheio = (bool) $linha['tanque_cheio'];

        if ($kmInicioTrecho === null) {
            // Ainda não temos um ponto de partida confiável — só um tanque
            // cheio confirmado pode abrir o primeiro trecho.
            if ($tanqueCheio) {
                $kmInicioTrecho = $kmAtual;
            }
            continue;
        }

        $litrosAcumulados += $litros;

        if ($tanqueCheio) {
            $kmTrecho = $kmAtual - $kmInicioTrecho;
            if ($kmTrecho > 0 && $litrosAcumulados > 0) {
                $trechos[] = [
                    'data'      => (string) $linha['data'],
                    'km_atual'  => $kmAtual,
                    'km_trecho' => $kmTrecho,
                    'litros'    => $litrosAcumulados,
                    'consumo'   => $kmTrecho / $litrosAcumulados,
                ];
            }
            $kmInicioTrecho   = $kmAtual;
            $litrosAcumulados = 0.0;
        }
    }

    return $trechos;
}

/**
 * Igual calcularTrechosConsumo(), mas SEM filtro de dono/colaborador —
 * exceção deliberada, de uso exclusivo do benchmark anônimo
 * (calcularBenchmarkConsumo()), que precisa olhar veículos de QUALQUER
 * conta pra montar a média agregada de "outros veículos parecidos". Nunca
 * expor o retorno desta função (por veículo) diretamente pra nenhum
 * usuário — só o agregado (média/percentil) sai pra fora, em
 * calcularBenchmarkConsumo().
 */
function calcularTrechosConsumoVeiculo(PDO $pdo, int $veiculoId): array
{
    $stmt = $pdo->prepare(
        "SELECT data, km_atual, litros, tanque_cheio FROM registros
         WHERE veiculo_id = :veiculo_id AND tipo_registro = 'Abastecimento' AND litros IS NOT NULL
         ORDER BY km_atual ASC"
    );
    $stmt->bindValue(':veiculo_id', $veiculoId, PDO::PARAM_INT);
    $stmt->execute();

    return acumularTrechosConsumo($stmt->fetchAll());
}

const BENCHMARK_CONSUMO_MIN_AMOSTRA = 5; // k-anonimato: não mostra nada com menos de 5 OUTROS veículos no mesmo segmento

/**
 * "Como você está vs a média": compara o consumo médio do veículo com o de
 * OUTROS veículos do mesmo segmento (mesmo tipo — Moto/Carro/Outro — e
 * mesmo combustível predominante, pra não misturar consumo de gasolina com
 * etanol/diesel/GNV, que não são comparáveis). Sempre agregado — nunca
 * expõe o consumo de nenhum outro veículo/usuário individualmente, e exige
 * uma amostra mínima (BENCHMARK_CONSUMO_MIN_AMOSTRA) de outros veículos
 * antes de mostrar qualquer número, pra não virar, na prática, "o consumo
 * daquele outro veículo específico" quando a amostra é pequena demais.
 *
 * Retorna null se o próprio veículo não tem consumo calculável, ou se não
 * há amostra suficiente de outros veículos no mesmo segmento.
 */
function calcularBenchmarkConsumo(PDO $pdo, int $usuarioId, int $veiculoId): ?array
{
    if (!usuarioTemAcessoVeiculo($pdo, $usuarioId, $veiculoId)) {
        return null;
    }

    $trechosProprios = calcularTrechosConsumoVeiculo($pdo, $veiculoId);
    if (!$trechosProprios) {
        return null;
    }
    $consumosProprios = array_column($trechosProprios, 'consumo');
    $seuConsumo = array_sum($consumosProprios) / count($consumosProprios);

    $veiculoStmt = $pdo->prepare('SELECT tipo FROM veiculos WHERE id = :id');
    $veiculoStmt->execute([':id' => $veiculoId]);
    $tipo = $veiculoStmt->fetchColumn();
    if ($tipo === false) {
        return null;
    }

    // Combustível predominante do próprio veículo: o do abastecimento mais
    // recente (por km) — critério simples, evita ter que calcular moda.
    $combustivelStmt = $pdo->prepare(
        "SELECT combustivel FROM registros
         WHERE veiculo_id = :veiculo_id AND tipo_registro = 'Abastecimento'
         ORDER BY km_atual DESC LIMIT 1"
    );
    $combustivelStmt->execute([':veiculo_id' => $veiculoId]);
    $combustivel = $combustivelStmt->fetchColumn();
    if ($combustivel === false) {
        return null;
    }

    // Outros veículos do mesmo segmento (tipo + combustível predominante),
    // nunca incluindo veículo do próprio usuário (nem outro veículo dele).
    $candidatosStmt = $pdo->prepare(
        "SELECT DISTINCT t.veiculo_id FROM (
            SELECT r.veiculo_id,
                   FIRST_VALUE(r.combustivel) OVER (PARTITION BY r.veiculo_id ORDER BY r.km_atual DESC) AS combustivel_recente
            FROM registros r
            INNER JOIN veiculos v ON v.id = r.veiculo_id
            WHERE v.tipo = :tipo AND r.tipo_registro = 'Abastecimento'
              AND v.usuario_id != :usuario_id
              AND NOT EXISTS (SELECT 1 FROM veiculo_compartilhamentos vc WHERE vc.veiculo_id = v.id AND vc.usuario_id = :usuario_id2)
         ) t
         WHERE t.combustivel_recente = :combustivel
         LIMIT 500"
    );
    $candidatosStmt->bindValue(':tipo', $tipo);
    $candidatosStmt->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
    $candidatosStmt->bindValue(':usuario_id2', $usuarioId, PDO::PARAM_INT);
    $candidatosStmt->bindValue(':combustivel', $combustivel);
    $candidatosStmt->execute();

    $mediasOutros = [];
    foreach ($candidatosStmt->fetchAll(PDO::FETCH_COLUMN) as $outroVeiculoId) {
        $trechos = calcularTrechosConsumoVeiculo($pdo, (int) $outroVeiculoId);
        if ($trechos) {
            $consumos = array_column($trechos, 'consumo');
            $mediasOutros[] = array_sum($consumos) / count($consumos);
        }
    }

    if (count($mediasOutros) < BENCHMARK_CONSUMO_MIN_AMOSTRA) {
        return null;
    }

    $mediaOutros = array_sum($mediasOutros) / count($mediasOutros);
    $piores = count(array_filter($mediasOutros, static fn(float $m): bool => $m <= $seuConsumo));
    $percentil = (int) round(($piores / count($mediasOutros)) * 100);

    return [
        'seu_consumo'             => round($seuConsumo, 1),
        'media_outros'            => round($mediaOutros, 1),
        'diferenca_percentual'    => $mediaOutros > 0 ? (int) round((($seuConsumo - $mediaOutros) / $mediaOutros) * 100) : 0,
        'percentil'               => $percentil,
        'amostra'                 => count($mediasOutros),
        'tipo'                    => $tipo,
        'combustivel'             => $combustivel,
    ];
}

/**
 * KM/L do trecho mais recente (por KM), respeitando tanque_cheio — ver
 * calcularTrechosConsumo(). Sempre restrito aos veículos do usuário informado.
 */
function calcularUltimaMedia(PDO $pdo, int $usuarioId, ?int $veiculoId = null): ?float
{
    if ($veiculoId !== null) {
        $trechos = calcularTrechosConsumo($pdo, $usuarioId, $veiculoId);
        $ultimo  = end($trechos);
        return $ultimo !== false ? round($ultimo['consumo'], 1) : null;
    }

    // Sem veiculo_id: pega o trecho mais recente entre TODOS os veículos
    // acessíveis ao usuário — próprios ou compartilhados (cada um calculado
    // com seu próprio corte tanque-cheio).
    $melhorTrecho = null;
    foreach (array_column(veiculosAcessiveis($pdo, $usuarioId), 'id') as $vid) {
        $trechos = calcularTrechosConsumo($pdo, $usuarioId, (int) $vid);
        $ultimo  = end($trechos);
        if ($ultimo !== false && ($melhorTrecho === null || $ultimo['km_atual'] > $melhorTrecho['km_atual'])) {
            $melhorTrecho = $ultimo;
        }
    }

    return $melhorTrecho !== null ? round($melhorTrecho['consumo'], 1) : null;
}

/**
 * Estimativa de km/l pros dois abastecimentos mais recentes (por KM), SEM
 * exigir tanque cheio em nenhum dos dois — é a mesma conta simples de antes
 * da v1.15.0 (km desde o abastecimento anterior ÷ litros do atual).
 *
 * Só existe pra alimentar um número provisório no dashboard quando
 * calcularUltimaMedia() ainda não tem nenhum trecho fechado (ver
 * calcularTrechosConsumo) — SEMPRE rotulado como estimativa na tela, nunca
 * usado nas estatísticas oficiais (calcularEstatisticasVeiculo) nem na
 * detecção de consumo em queda (detectarAnomaliasRegistro): lá, um valor
 * não confiável geraria alerta falso ou distorceria a comparação entre
 * veículos. Sem garantia de precisão — se nenhum dos dois abastecimentos
 * realmente encheu o tanque, o número pode vir bem torto (é o preço de dar
 * uma resposta imediata em vez de esperar o próximo tanque cheio).
 */
function calcularUltimaMediaEstimativa(PDO $pdo, int $usuarioId, int $veiculoId): ?float
{
    $stmt = $pdo->prepare(
        "SELECT r.km_atual, r.litros FROM registros r
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE " . condicaoAcessoVeiculo('v') . " AND v.id = :veiculo_id
           AND r.tipo_registro = 'Abastecimento' AND r.litros IS NOT NULL
         ORDER BY r.km_atual DESC LIMIT 2"
    );
    bindAcessoVeiculo($stmt, $usuarioId);
    $stmt->bindValue(':veiculo_id', $veiculoId, PDO::PARAM_INT);
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
        bindAcessoVeiculo($stmt, $usuarioId);
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
         WHERE ' . condicaoAcessoVeiculo('v') . ' AND v.id = :veiculo_id' . $filtroData
    );
    $bind($stmt);
    $stmt->execute();
    $gasto = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(GREATEST(km_atual - km_anterior, 0)), 0) FROM (
            SELECT r.km_atual, LAG(r.km_atual) OVER (ORDER BY r.km_atual) AS km_anterior
            FROM registros r
            INNER JOIN veiculos v ON v.id = r.veiculo_id
            WHERE ' . condicaoAcessoVeiculo('v') . ' AND v.id = :veiculo_id' . $filtroData . '
         ) t WHERE km_anterior IS NOT NULL'
    );
    $bind($stmt);
    $stmt->execute();
    $kmRodado = (int) $stmt->fetchColumn();

    $trechos  = calcularTrechosConsumo($pdo, $usuarioId, $veiculoId, $dataInicio, $dataFim);
    $consumos = array_column($trechos, 'consumo');

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
         WHERE " . condicaoAcessoVeiculo('v')
    );
    bindAcessoVeiculo($mesesStmt, $usuarioId);
    $mesesStmt->execute();
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
         WHERE " . condicaoAcessoVeiculo('v') . " AND r.tipo_registro = 'Abastecimento'"
    );
    bindAcessoVeiculo($totalStmt, $usuarioId);
    $totalStmt->execute();
    $totalAbastecimentos = (int) $totalStmt->fetchColumn();

    // Consumo médio do mês atual x mês anterior (só entre abastecimentos
    // consecutivos do MESMO veículo) — melhora vira a conquista "Economia do Mês".
    $consumoStmt = $pdo->prepare(
        "SELECT mes, AVG((km_atual - km_anterior) / litros) AS consumo_medio FROM (
            SELECT DATE_FORMAT(r.data, '%Y-%m') AS mes, r.km_atual, r.litros,
                   LAG(r.km_atual) OVER (PARTITION BY r.veiculo_id ORDER BY r.km_atual) AS km_anterior
            FROM registros r INNER JOIN veiculos v ON v.id = r.veiculo_id
            WHERE " . condicaoAcessoVeiculo('v') . "
              AND r.tipo_registro = 'Abastecimento' AND r.litros IS NOT NULL
         ) t
         WHERE km_anterior IS NOT NULL AND km_atual > km_anterior
         GROUP BY mes ORDER BY mes DESC LIMIT 2"
    );
    bindAcessoVeiculo($consumoStmt, $usuarioId);
    $consumoStmt->execute();
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
         WHERE " . condicaoAcessoVeiculo('v') . " AND l.concluido_em IS NULL"
    );
    bindAcessoVeiculo($lembretesStmt, $usuarioId);
    $lembretesStmt->execute();
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

// --- Compartilhamento de veículo entre contas -------------------------------
// Um veículo continua tendo um único dono (veiculos.usuario_id) — quem pode
// editar/excluir o veículo, gerenciar o passaporte (link público) e
// convidar/remover colaboradores. veiculo_compartilhamentos é a lista de
// contas ADICIONAIS que passam a poder registrar/ver abastecimentos,
// manutenções, despesas e lembretes desse veículo (ver
// db/migrations/0008_veiculo_compartilhamento.sql).
//
// DECISÃO DE PRODUTO: o colaborador convidado enxerga o HISTÓRICO COMPLETO
// do veículo, não só os registros a partir do convite. Dois motivos: (1) é
// o mesmo veículo físico — esconder parte do histórico fragmentaria as
// contas de km/l e gasto total, que dependem de uma sequência contínua de
// odômetro; (2) o caso de uso ("casal dividindo carro") pressupõe
// transparência mútua sobre o veículo compartilhado. Quem quiser manter um
// histórico privado antes de compartilhar deve cadastrar um veículo novo.

/**
 * Condição SQL (a incluir num WHERE/ON já filtrado por $alias.id) que
 * verifica se o usuário é o DONO do veículo OU um colaborador convidado.
 * Usa dois placeholders nomeados distintos (não o mesmo nome duas vezes)
 * porque o driver MySQL do PDO, com EMULATE_PREPARES desligado (ver
 * conexao.php), não aceita reaproveitar um placeholder nomeado repetido na
 * mesma query — bindAcessoVeiculo() cobre os dois com o mesmo valor.
 */
function condicaoAcessoVeiculo(string $alias = 'v'): string
{
    return "({$alias}.usuario_id = :acesso_usuario_id OR EXISTS (
        SELECT 1 FROM veiculo_compartilhamentos vc
        WHERE vc.veiculo_id = {$alias}.id AND vc.usuario_id = :acesso_usuario_id_vc
    ))";
}

/** Faz o bind dos dois placeholders usados por condicaoAcessoVeiculo(). */
function bindAcessoVeiculo(PDOStatement $stmt, int $usuarioId): void
{
    $stmt->bindValue(':acesso_usuario_id', $usuarioId, PDO::PARAM_INT);
    $stmt->bindValue(':acesso_usuario_id_vc', $usuarioId, PDO::PARAM_INT);
}

/** Versão booleana pontual (um veículo específico), pra checagens simples. */
function usuarioTemAcessoVeiculo(PDO $pdo, int $usuarioId, int $veiculoId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM veiculos v WHERE v.id = :veiculo_id AND ' . condicaoAcessoVeiculo('v')
    );
    $stmt->bindValue(':veiculo_id', $veiculoId, PDO::PARAM_INT);
    bindAcessoVeiculo($stmt, $usuarioId);
    $stmt->execute();

    return (bool) $stmt->fetchColumn();
}

/**
 * Todos os veículos que o usuário pode ver/usar: os próprios e os
 * compartilhados com ele. 'compartilhado' distingue os dois na tela (ex.:
 * "Moto do João (compartilhado)"), sem misturar dados de outros usuários —
 * cada linha continua sendo só metadado do próprio veículo.
 */
function veiculosAcessiveis(PDO $pdo, int $usuarioId): array
{
    $stmt = $pdo->prepare(
        'SELECT v.id, v.nome, v.tipo, v.cor, v.placa, v.tanque_litros, v.peso_kg,
                (v.usuario_id = :usuario_id) AS e_dono
         FROM veiculos v
         WHERE ' . condicaoAcessoVeiculo('v') . '
         ORDER BY v.nome'
    );
    $stmt->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
    bindAcessoVeiculo($stmt, $usuarioId);
    $stmt->execute();

    return array_map(static function (array $v): array {
        $v['e_dono'] = (bool) $v['e_dono'];
        return $v;
    }, $stmt->fetchAll());
}

/**
 * Convida (por e-mail) uma conta a colaborar num veículo — mesmo padrão do
 * convite de conta (convidar.php): token de 32 bytes, só o hash fica no
 * banco, validade de 7 dias. Só o DONO do veículo pode convidar; retorna
 * null se $usuarioId não for dono, sem criar nada.
 */
function criarConviteVeiculo(PDO $pdo, int $usuarioId, int $veiculoId, string $email): ?string
{
    $dono = $pdo->prepare('SELECT 1 FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
    $dono->execute([':id' => $veiculoId, ':usuario_id' => $usuarioId]);
    if (!$dono->fetchColumn()) {
        return null;
    }

    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiraEm  = (new DateTime())->modify('+7 days')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO veiculo_convites (veiculo_id, email, token_hash, criado_por, expira_em)
         VALUES (:veiculo_id, :email, :token_hash, :criado_por, :expira_em)'
    );
    $stmt->execute([
        ':veiculo_id' => $veiculoId,
        ':email'      => $email,
        ':token_hash' => $tokenHash,
        ':criado_por' => $usuarioId,
        ':expira_em'  => $expiraEm,
    ]);

    return $token;
}

/**
 * Aceita um convite de veículo: cria o compartilhamento pro usuário logado
 * e marca o convite como usado. Trava a linha (FOR UPDATE) pra garantir uso
 * único mesmo com duas requisições simultâneas, mesmo padrão de convite.php.
 * Retorna o veiculo_id em caso de sucesso, ou null se o token for
 * inválido/expirado/já usado.
 */
function aceitarConviteVeiculo(PDO $pdo, string $token, int $usuarioId): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $tokenHash = hash('sha256', $token);

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            'SELECT id, veiculo_id FROM veiculo_convites
             WHERE token_hash = :token_hash AND usado_em IS NULL AND expira_em > NOW() FOR UPDATE'
        );
        $lock->execute([':token_hash' => $tokenHash]);
        $convite = $lock->fetch();

        if (!$convite) {
            $pdo->rollBack();
            return null;
        }

        $pdo->prepare(
            'INSERT IGNORE INTO veiculo_compartilhamentos (veiculo_id, usuario_id) VALUES (:veiculo_id, :usuario_id)'
        )->execute([':veiculo_id' => $convite['veiculo_id'], ':usuario_id' => $usuarioId]);

        $pdo->prepare('UPDATE veiculo_convites SET usado_em = NOW() WHERE id = :id')
            ->execute([':id' => $convite['id']]);

        $pdo->commit();
        return (int) $convite['veiculo_id'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Remove um compartilhamento. $quemRemove pode ser o DONO do veículo
 * (removendo qualquer colaborador) ou o próprio colaborador (saindo por
 * conta própria) — nunca um terceiro sem relação com o veículo. Retorna
 * true se algo foi removido.
 */
function removerCompartilhamentoVeiculo(PDO $pdo, int $quemRemove, int $veiculoId, int $usuarioIdRemovido): bool
{
    $ehDono = $pdo->prepare('SELECT 1 FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
    $ehDono->execute([':id' => $veiculoId, ':usuario_id' => $quemRemove]);
    $autorizado = (bool) $ehDono->fetchColumn() || $quemRemove === $usuarioIdRemovido;
    if (!$autorizado) {
        return false;
    }

    $stmt = $pdo->prepare(
        'DELETE FROM veiculo_compartilhamentos WHERE veiculo_id = :veiculo_id AND usuario_id = :usuario_id'
    );
    $stmt->execute([':veiculo_id' => $veiculoId, ':usuario_id' => $usuarioIdRemovido]);

    return $stmt->rowCount() > 0;
}

/** Colaboradores atuais de um veículo (pra tela de gerenciamento do dono). */
function colaboradoresVeiculo(PDO $pdo, int $veiculoId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.nome, u.email, vc.criado_em
         FROM veiculo_compartilhamentos vc
         INNER JOIN usuarios u ON u.id = vc.usuario_id
         WHERE vc.veiculo_id = :veiculo_id
         ORDER BY vc.criado_em'
    );
    $stmt->execute([':veiculo_id' => $veiculoId]);
    return $stmt->fetchAll();
}

// --- Postos de combustível ----------------------------------------------------
// Lista pessoal (por conta) de postos, com localização opcional e marcação
// de favorito — usada no registro de abastecimento (posto_id opcional) e
// pra comparar preço médio por posto em relatorios.php. Não é
// compartilhada entre contas que dividem um veículo (ver
// veiculo_compartilhamentos): cada colaborador mantém a própria lista.

function listarPostos(PDO $pdo, int $usuarioId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, nome, localizacao, favorito FROM postos
         WHERE usuario_id = :usuario_id ORDER BY favorito DESC, nome'
    );
    $stmt->execute([':usuario_id' => $usuarioId]);
    return $stmt->fetchAll();
}

function criarPosto(PDO $pdo, int $usuarioId, string $nome, ?string $localizacao): array
{
    $nome = trim($nome);
    if ($nome === '' || mb_strlen($nome) > 100) {
        return ['ok' => false, 'erro' => 'Nome do posto inválido (máx. 100 caracteres).'];
    }
    $localizacao = $localizacao !== null ? trim($localizacao) : null;
    if ($localizacao !== null && mb_strlen($localizacao) > 255) {
        return ['ok' => false, 'erro' => 'Localização muito longa (máx. 255 caracteres).'];
    }

    $stmt = $pdo->prepare('INSERT INTO postos (usuario_id, nome, localizacao) VALUES (:usuario_id, :nome, :localizacao)');
    $stmt->execute([
        ':usuario_id'  => $usuarioId,
        ':nome'        => $nome,
        ':localizacao' => $localizacao !== '' ? $localizacao : null,
    ]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

/** Alterna favorito/nome/localização de um posto, escopado ao dono. */
function atualizarPosto(PDO $pdo, int $usuarioId, int $postoId, string $nome, ?string $localizacao): array
{
    $nome = trim($nome);
    if ($nome === '' || mb_strlen($nome) > 100) {
        return ['ok' => false, 'erro' => 'Nome do posto inválido (máx. 100 caracteres).'];
    }
    $localizacao = $localizacao !== null ? trim($localizacao) : null;

    $stmt = $pdo->prepare(
        'UPDATE postos SET nome = :nome, localizacao = :localizacao
         WHERE id = :id AND usuario_id = :usuario_id'
    );
    $stmt->execute([
        ':nome'        => $nome,
        ':localizacao' => $localizacao !== '' ? $localizacao : null,
        ':id'          => $postoId,
        ':usuario_id'  => $usuarioId,
    ]);

    return ['ok' => $stmt->rowCount() > 0];
}

function alternarFavoritoPosto(PDO $pdo, int $usuarioId, int $postoId): void
{
    $stmt = $pdo->prepare(
        'UPDATE postos SET favorito = NOT favorito WHERE id = :id AND usuario_id = :usuario_id'
    );
    $stmt->execute([':id' => $postoId, ':usuario_id' => $usuarioId]);
}

/** Exclui um posto do usuário — registros que apontavam pra ele ficam com posto_id NULL (FK ON DELETE SET NULL). */
function excluirPosto(PDO $pdo, int $usuarioId, int $postoId): bool
{
    $stmt = $pdo->prepare('DELETE FROM postos WHERE id = :id AND usuario_id = :usuario_id');
    $stmt->execute([':id' => $postoId, ':usuario_id' => $usuarioId]);

    return $stmt->rowCount() > 0;
}

/**
 * Preço médio por litro pago em cada posto, dentro do recorte de
 * veículo/período já usado no resto de relatorios.php — só considera
 * abastecimentos com posto informado (os sem posto não entram em nenhuma
 * linha da comparação). Sempre restrito aos veículos acessíveis ao usuário.
 */
function precoMedioPorPosto(PDO $pdo, int $usuarioId, ?int $veiculoId, ?string $dataInicio, ?string $dataFim): array
{
    $filtro = ($veiculoId !== null ? ' AND r.veiculo_id = :veiculo_id' : '')
        . ($dataInicio !== null ? ' AND r.data >= :data_inicio' : '')
        . ($dataFim !== null ? ' AND r.data <= :data_fim' : '');

    $stmt = $pdo->prepare(
        "SELECT p.id, p.nome, p.favorito,
                COUNT(*) AS total_abastecimentos,
                SUM(r.valor_pago) / SUM(r.litros) AS preco_medio_litro,
                MAX(r.data) AS ultimo_abastecimento
         FROM registros r
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         INNER JOIN postos p ON p.id = r.posto_id
         WHERE " . condicaoAcessoVeiculo('v') . "
           AND p.usuario_id = :usuario_id_posto
           AND r.tipo_registro = 'Abastecimento' AND r.litros IS NOT NULL AND r.litros > 0" . $filtro . '
         GROUP BY p.id, p.nome, p.favorito
         ORDER BY preco_medio_litro ASC'
    );
    bindAcessoVeiculo($stmt, $usuarioId);
    $stmt->bindValue(':usuario_id_posto', $usuarioId, PDO::PARAM_INT);
    if ($veiculoId !== null) {
        $stmt->bindValue(':veiculo_id', $veiculoId, PDO::PARAM_INT);
    }
    if ($dataInicio !== null) {
        $stmt->bindValue(':data_inicio', $dataInicio);
    }
    if ($dataFim !== null) {
        $stmt->bindValue(':data_fim', $dataFim);
    }
    $stmt->execute();

    return $stmt->fetchAll();
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
    $postoIdStr       = (string) ($dados['posto_id'] ?? '');
    $postoId          = $postoIdStr === '' ? null : filter_var($postoIdStr, FILTER_VALIDATE_INT);
    // Ausente = assume tanque cheio (clientes antigos, ainda sem o campo,
    // mantêm o comportamento anterior à migração 0002). Formulários HTML
    // sempre mandam '1' ou '0' explicitamente (ver adicionar.php).
    $tanqueCheio      = filter_var($dados['tanque_cheio'] ?? '1', FILTER_VALIDATE_BOOLEAN);
    $descricao        = trim((string) ($dados['descricao'] ?? ''));
    $dataRegistro     = DateTime::createFromFormat('Y-m-d', $dataStr);

    if (!$veiculoId) {
        $erros[] = 'Selecione um veículo válido.';
    } elseif (!usuarioTemAcessoVeiculo($pdo, $usuarioId, $veiculoId)) {
        $erros[] = 'Veículo não encontrado.';
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
    if ($postoId !== null) {
        if ($postoId === false || $postoId <= 0) {
            $erros[] = 'Posto inválido.';
        } else {
            $existePosto = $pdo->prepare('SELECT 1 FROM postos WHERE id = :id AND usuario_id = :usuario_id');
            $existePosto->execute([':id' => $postoId, ':usuario_id' => $usuarioId]);
            if (!$existePosto->fetchColumn()) {
                $erros[] = 'Posto não encontrado.';
            }
        }
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
            'posto_id'          => $tipoRegistro === 'Abastecimento' ? $postoId : null,
            'litros'            => $tipoRegistro === 'Abastecimento' ? $litros : null,
            'tanque_cheio'      => $tipoRegistro === 'Abastecimento' ? $tanqueCheio : true,
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
        // Escopado por veiculo_id — bate com a UNIQUE KEY composta
        // (veiculo_id, client_uuid), ver migração 0006.
        $existente = $pdo->prepare('SELECT id FROM registros WHERE client_uuid = :uuid AND veiculo_id = :veiculo_id');
        $existente->execute([':uuid' => $clientUuid, ':veiculo_id' => $valores['veiculo_id']]);
        $id = $existente->fetchColumn();
        if ($id !== false) {
            return ['id' => (int) $id, 'novo' => false];
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO registros (veiculo_id, data, km_atual, tipo_registro, combustivel, posto_id, litros, tanque_cheio, categoria_despesa, valor_pago, descricao, client_uuid)
         VALUES (:veiculo_id, :data, :km_atual, :tipo_registro, :combustivel, :posto_id, :litros, :tanque_cheio, :categoria_despesa, :valor_pago, :descricao, :client_uuid)'
    );
    $stmt->execute([
        ':veiculo_id'        => $valores['veiculo_id'],
        ':data'              => $valores['data'],
        ':km_atual'          => $valores['km_atual'],
        ':tipo_registro'     => $valores['tipo_registro'],
        ':combustivel'       => $valores['combustivel'],
        // Ausente (chamadores antigos/testes que montam o array na mão) = sem posto.
        ':posto_id'          => $valores['posto_id'] ?? null,
        ':litros'            => $valores['litros'],
        // Ausente (chamadores antigos/testes que montam o array na mão) =
        // assume cheio, preservando o comportamento anterior à migração 0002.
        ':tanque_cheio'      => ($valores['tanque_cheio'] ?? true) ? 1 : 0,
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
        $trechos = array_column(calcularTrechosConsumo($pdo, $usuarioId, $veiculoId), 'consumo');
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
    } elseif (!usuarioTemAcessoVeiculo($pdo, $usuarioId, $veiculoId)) {
        $erros[] = 'Veículo não encontrado.';
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

// --- Importação de histórico via CSV -----------------------------------------
// Caminho inverso da exportação CSV de relatorios.php (mesmas 9 colunas,
// mesmo delimitador ';', mesma formatação de data/número). Todas as linhas
// importadas vão pro MESMO veículo escolhido na tela (importar.php) — a
// coluna "Veiculo" do CSV é só informativa aqui, não é usada pra rotear
// automaticamente entre veículos: decisão deliberada, casar nomes de
// veículo por texto é frágil (duplicidade, apelido diferente) e um alvo
// único e explícito é mais seguro (nunca insere num veículo que o usuário
// não escolheu na hora).

const CSV_IMPORTACAO_CABECALHO = ['Data', 'Veiculo', 'Tipo', 'Combustivel/Categoria', 'Litros', 'Km', 'TanqueCheio', 'Valor (R$)', 'Descricao'];
const CSV_IMPORTACAO_MAX_LINHAS = 2000;

/**
 * Quebra o texto CSV em linhas de colunas (delimitador ';', mesmo da
 * exportação), removendo BOM/cabeçalho. Não valida conteúdo — só a forma.
 *
 * @return array{ok: bool, erro?: string, linhas?: array<int, array<int, string>>}
 */
function analisarCsvImportacao(string $conteudo): array
{
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo) ?? $conteudo; // remove BOM, se vier
    $conteudo = str_replace("\r\n", "\n", $conteudo);
    $linhasBrutas = array_values(array_filter(explode("\n", $conteudo), static fn(string $l): bool => trim($l) !== ''));

    if (!$linhasBrutas) {
        return ['ok' => false, 'erro' => 'Arquivo vazio.'];
    }
    if (count($linhasBrutas) - 1 > CSV_IMPORTACAO_MAX_LINHAS) {
        return ['ok' => false, 'erro' => 'Arquivo com mais de ' . CSV_IMPORTACAO_MAX_LINHAS . ' linhas de dados — divida em arquivos menores.'];
    }

    $linhas = array_map(static fn(string $l): array => str_getcsv($l, ';'), $linhasBrutas);

    $cabecalho = array_map('trim', $linhas[0]);
    if ($cabecalho !== CSV_IMPORTACAO_CABECALHO) {
        return ['ok' => false, 'erro' => 'Cabeçalho do CSV não bate com o esperado (exporte de novo em Relatórios pra garantir o formato certo).'];
    }

    return ['ok' => true, 'linhas' => array_slice($linhas, 1)];
}

/**
 * Valida (e, se pedido, converte pros valores prontos de inserirRegistro())
 * uma linha de dados do CSV de importação. Reaproveita validarRegistro()
 * pra whitelist/limites — mesma regra de negócio do formulário/API, sem
 * duplicar validação.
 *
 * @param array<int, string> $colunas
 * @return array{ok: bool, erro?: string, valores?: array}
 */
function validarLinhaCsvImportacao(PDO $pdo, int $usuarioId, int $veiculoId, array $colunas): array
{
    if (count($colunas) !== count(CSV_IMPORTACAO_CABECALHO)) {
        return ['ok' => false, 'erro' => 'Número de colunas inválido (esperado ' . count(CSV_IMPORTACAO_CABECALHO) . ').'];
    }

    [$dataBr, , $tipo, $combustivelOuCategoria, $litrosBr, $kmBr, $tanqueCheioTexto, $valorBr, $descricao] = $colunas;

    $data = DateTime::createFromFormat('d/m/Y', trim($dataBr));
    if (!$data) {
        return ['ok' => false, 'erro' => 'Data inválida (esperado dd/mm/aaaa).'];
    }

    $paraFloat = static function (string $valor): ?float {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        $normalizado = str_replace(['.', ','], ['', '.'], $valor);
        return is_numeric($normalizado) ? (float) $normalizado : null;
    };

    $tipoNormalizado = trim($tipo);
    $tanqueCheioTexto = mb_strtolower(trim($tanqueCheioTexto));

    $dados = [
        'veiculo_id'        => (string) $veiculoId,
        'data'              => $data->format('Y-m-d'),
        'km_atual'          => trim($kmBr),
        'tipo_registro'     => $tipoNormalizado,
        'combustivel'       => $tipoNormalizado === 'Abastecimento' ? trim($combustivelOuCategoria) : '',
        'litros'            => $tipoNormalizado === 'Abastecimento' ? (string) ($paraFloat($litrosBr) ?? '') : '',
        'tanque_cheio'      => in_array($tanqueCheioTexto, ['parcial', '0', 'nao', 'não'], true) ? '0' : '1',
        'categoria_despesa' => $tipoNormalizado === 'Despesa' ? trim($combustivelOuCategoria) : '',
        'valor_pago'        => (string) ($paraFloat($valorBr) ?? ''),
        'descricao'         => trim($descricao),
    ];

    $resultado = validarRegistro($pdo, $usuarioId, $dados);
    if (!$resultado['ok']) {
        return ['ok' => false, 'erro' => implode(' ', $resultado['erros'])];
    }

    return ['ok' => true, 'valores' => $resultado['valores']];
}

// --- Foto de comprovante -----------------------------------------------------
// Anexo opcional (nota fiscal/recibo) num registro, guardado como BLOB no
// MySQL — ver db/migrations/0009_registro_fotos.sql pro porquê de não ser
// arquivo em disco. Precisa funcionar offline: o cliente compacta a imagem
// (assets/js/adicionar.js) e manda como data URL num campo de formulário
// comum (foto_base64) — não é upload de arquivo de verdade (file_uploads
// continua Off, ver docker/php/php.ini), então passa pela MESMA fila
// offline (IndexedDB) e pelo MESMO endpoint JSON (api/registro.php) que já
// existem pros outros campos do registro, sem precisar de um passo de
// upload à parte que pudesse falhar sozinho.

const FOTO_REGISTRO_MAX_BYTES = 900000; // ~900KB decodificado — folga sobre o alvo de compressão do cliente (~300KB)
const FOTO_REGISTRO_MIME_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Decodifica, valida (tamanho + mime real via finfo, nunca confiando no que
 * o cliente alega) e grava a foto de um registro — substitui a anterior, se
 * já existir. Sempre escopado ao usuário informado (dono ou colaborador do
 * veículo do registro, ver condicaoAcessoVeiculo) — nunca aceita anexar
 * foto num registro que esse usuário não pode ver/editar.
 *
 * Aceita tanto uma data URL completa ("data:image/jpeg;base64,...") quanto
 * só o base64 cru, pra funcionar igual vindo do formulário quanto da fila
 * offline (que guarda e reenvia o mesmo valor do campo do formulário).
 */
function salvarFotoRegistro(PDO $pdo, int $usuarioId, int $registroId, string $dataUrlOuBase64): array
{
    $base64 = $dataUrlOuBase64;
    if (preg_match('#^data:[a-zA-Z0-9/+.-]+;base64,(.+)$#', $dataUrlOuBase64, $m)) {
        $base64 = $m[1];
    }

    $binario = base64_decode($base64, true);
    if ($binario === false || $binario === '') {
        return ['ok' => false, 'erro' => 'Foto inválida.'];
    }
    if (strlen($binario) > FOTO_REGISTRO_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Foto muito grande (máx. ~900KB depois de compactada).'];
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binario);
    if (!in_array($mime, FOTO_REGISTRO_MIME_PERMITIDOS, true)) {
        return ['ok' => false, 'erro' => 'Formato de imagem não suportado (use JPEG, PNG ou WEBP).'];
    }

    $existe = $pdo->prepare(
        'SELECT 1 FROM registros r INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE r.id = :registro_id AND ' . condicaoAcessoVeiculo('v')
    );
    $existe->bindValue(':registro_id', $registroId, PDO::PARAM_INT);
    bindAcessoVeiculo($existe, $usuarioId);
    $existe->execute();
    if (!$existe->fetchColumn()) {
        return ['ok' => false, 'erro' => 'Registro não encontrado.'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO registro_fotos (registro_id, mime_type, tamanho_bytes, dados)
         VALUES (:registro_id, :mime_type, :tamanho_bytes, :dados)
         ON DUPLICATE KEY UPDATE mime_type = VALUES(mime_type), tamanho_bytes = VALUES(tamanho_bytes), dados = VALUES(dados)'
    );
    $stmt->bindValue(':registro_id', $registroId, PDO::PARAM_INT);
    $stmt->bindValue(':mime_type', $mime);
    $stmt->bindValue(':tamanho_bytes', strlen($binario), PDO::PARAM_INT);
    $stmt->bindValue(':dados', $binario, PDO::PARAM_LOB);
    $stmt->execute();

    return ['ok' => true];
}

/** Remove a foto de um registro, escopado ao dono/colaborador do veículo. */
function removerFotoRegistro(PDO $pdo, int $usuarioId, int $registroId): void
{
    $stmt = $pdo->prepare(
        'DELETE rf FROM registro_fotos rf
         INNER JOIN registros r ON r.id = rf.registro_id
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE rf.registro_id = :registro_id AND ' . condicaoAcessoVeiculo('v')
    );
    $stmt->bindValue(':registro_id', $registroId, PDO::PARAM_INT);
    bindAcessoVeiculo($stmt, $usuarioId);
    $stmt->execute();
}

/**
 * Só checa se o registro tem foto (sem carregar o BLOB) — usada nas telas de
 * listagem/edição pra decidir se mostra o ícone/prévia, sem o custo de
 * carregar a imagem inteira na memória do PHP à toa.
 */
function temFotoRegistro(PDO $pdo, int $usuarioId, int $registroId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM registro_fotos rf
         INNER JOIN registros r ON r.id = rf.registro_id
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE rf.registro_id = :registro_id AND ' . condicaoAcessoVeiculo('v')
    );
    $stmt->bindValue(':registro_id', $registroId, PDO::PARAM_INT);
    bindAcessoVeiculo($stmt, $usuarioId);
    $stmt->execute();

    return (bool) $stmt->fetchColumn();
}

/**
 * Foto de um registro (mime + bytes), escopado ao dono/colaborador do
 * veículo — usada por foto.php pra servir a imagem sem vazar a de outro
 * usuário (IDOR).
 */
function buscarFotoRegistro(PDO $pdo, int $usuarioId, int $registroId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT rf.mime_type, rf.dados FROM registro_fotos rf
         INNER JOIN registros r ON r.id = rf.registro_id
         INNER JOIN veiculos v ON v.id = r.veiculo_id
         WHERE rf.registro_id = :registro_id AND ' . condicaoAcessoVeiculo('v')
    );
    $stmt->bindValue(':registro_id', $registroId, PDO::PARAM_INT);
    bindAcessoVeiculo($stmt, $usuarioId);
    $stmt->execute();
    $linha = $stmt->fetch();

    return $linha === false ? null : $linha;
}

// --- Passaporte do veículo -------------------------------------------------
// Link público (sem login), read-only, com o histórico completo de um
// veículo — pra o dono provar procedência na hora de vender (ver
// db/migrations/0007_veiculo_passaportes.sql). Só o hash SHA-256 do token
// fica no banco (mesmo padrão de convites/redefinição de senha); o token
// puro só existe uma vez, no momento em que é gerado, pra ser copiado/
// compartilhado pelo dono — nunca fica recuperável depois.

/**
 * Gera (ou substitui, se já existir) o link público do veículo. Sempre
 * escopado ao dono do veículo — retorna null sem tocar em nada se o veículo
 * não existir ou não pertencer a esse usuário, prevenindo IDOR. Retorna o
 * token em texto puro (só existe aqui, uma vez — o banco guarda só o hash).
 */
function criarOuRotacionarPassaporte(PDO $pdo, int $usuarioId, int $veiculoId): ?string
{
    $existe = $pdo->prepare('SELECT 1 FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
    $existe->execute([':id' => $veiculoId, ':usuario_id' => $usuarioId]);
    if (!$existe->fetchColumn()) {
        return null;
    }

    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        'INSERT INTO veiculo_passaportes (veiculo_id, token_hash, criado_por)
         VALUES (:veiculo_id, :token_hash, :criado_por)
         ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), criado_por = VALUES(criado_por)'
    );
    $stmt->execute([
        ':veiculo_id' => $veiculoId,
        ':token_hash' => $tokenHash,
        ':criado_por' => $usuarioId,
    ]);

    return $token;
}

/** Revoga (apaga) o link público do veículo, escopado ao dono. */
function revogarPassaporte(PDO $pdo, int $usuarioId, int $veiculoId): bool
{
    $stmt = $pdo->prepare(
        'DELETE p FROM veiculo_passaportes p
         INNER JOIN veiculos v ON v.id = p.veiculo_id
         WHERE p.veiculo_id = :veiculo_id AND v.usuario_id = :usuario_id'
    );
    $stmt->execute([':veiculo_id' => $veiculoId, ':usuario_id' => $usuarioId]);

    return $stmt->rowCount() > 0;
}

/** Estado atual do link público do veículo (existe ou não), escopado ao dono. */
function passaporteAtivo(PDO $pdo, int $usuarioId, int $veiculoId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM veiculo_passaportes p
         INNER JOIN veiculos v ON v.id = p.veiculo_id
         WHERE p.veiculo_id = :veiculo_id AND v.usuario_id = :usuario_id'
    );
    $stmt->execute([':veiculo_id' => $veiculoId, ':usuario_id' => $usuarioId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Resolve um token de passaporte público pro veículo/dono correspondentes,
 * ou null se o token for inválido/inexistente. Usado pela página pública
 * (sem autenticação) — a partir daqui, todo dado exibido é sempre filtrado
 * por esse veiculo_id + usuario_id (dono), nunca por dado solto, o que
 * impede o link de um veículo vazar dados de outro veículo ou de outra
 * conta.
 *
 * @return array{veiculo_id: int, usuario_id: int}|null
 */
function buscarVeiculoPorTokenPassaporte(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        'SELECT p.veiculo_id, v.usuario_id
         FROM veiculo_passaportes p
         INNER JOIN veiculos v ON v.id = p.veiculo_id
         WHERE p.token_hash = :token_hash'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $linha = $stmt->fetch();

    return $linha === false ? null : ['veiculo_id' => (int) $linha['veiculo_id'], 'usuario_id' => (int) $linha['usuario_id']];
}

/** Insere um lembrete já validado (ver validarLembrete); idempotente via client_uuid. */
function inserirLembrete(PDO $pdo, array $valores, ?string $clientUuid = null): int
{
    if ($clientUuid !== null) {
        // Mesmo raciocínio de inserirRegistro(): escopado por veiculo_id,
        // não global — bate com a UNIQUE KEY composta (migração 0006).
        $existente = $pdo->prepare('SELECT id FROM lembretes WHERE client_uuid = :uuid AND veiculo_id = :veiculo_id');
        $existente->execute([':uuid' => $clientUuid, ':veiculo_id' => $valores['veiculo_id']]);
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
