<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$veiculos = veiculosAcessiveis($pdo, $usuario['id']);

$erros = [];
$preview = null;
$resumoImportacao = null;
$csvConteudo = '';
$veiculoIdEscolhido = filter_input(INPUT_POST, 'veiculo_id', FILTER_VALIDATE_INT) ?: null;
$modo = in_array($_POST['modo'] ?? '', ['pular', 'abortar'], true) ? $_POST['modo'] : 'pular';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificarOuFalhar();

    $acao = (string) ($_POST['acao'] ?? '');
    $csvConteudo = (string) ($_POST['csv_conteudo'] ?? '');

    if (!$veiculoIdEscolhido || !usuarioTemAcessoVeiculo($pdo, $usuario['id'], $veiculoIdEscolhido)) {
        $erros[] = 'Selecione um veículo válido.';
    } elseif ($csvConteudo === '') {
        $erros[] = 'Selecione um arquivo CSV.';
    } else {
        $analise = analisarCsvImportacao($csvConteudo);
        if (!$analise['ok']) {
            $erros[] = $analise['erro'];
        } else {
            $linhasValidadas = [];
            foreach ($analise['linhas'] as $indice => $colunas) {
                $numeroLinha = $indice + 2; // +1 (índice base 0) +1 (linha 1 é o cabeçalho)
                $resultado = validarLinhaCsvImportacao($pdo, $usuario['id'], $veiculoIdEscolhido, $colunas);
                $linhasValidadas[] = [
                    'numero'  => $numeroLinha,
                    'ok'      => $resultado['ok'],
                    'erro'    => $resultado['erro'] ?? null,
                    'valores' => $resultado['valores'] ?? null,
                    'bruta'   => $colunas,
                ];
            }

            $totalLinhas = count($linhasValidadas);
            $totalValidas = count(array_filter($linhasValidadas, static fn(array $l): bool => $l['ok']));
            $totalInvalidas = $totalLinhas - $totalValidas;

            if ($acao === 'confirmar') {
                if ($modo === 'abortar' && $totalInvalidas > 0) {
                    $erros[] = "Importação abortada: {$totalInvalidas} de {$totalLinhas} linha(s) com erro (modo \"abortar se houver erro\" selecionado). Corrija o arquivo ou troque pra \"pular linhas com erro\".";
                } else {
                    $importados = 0;
                    foreach ($linhasValidadas as $linha) {
                        if (!$linha['ok']) {
                            continue;
                        }
                        // Sem client_uuid (importação em massa não passa pela
                        // fila offline) e sem detectarAnomaliasRegistro — um
                        // histórico importado de uma vez geraria dezenas de
                        // alertas de anomalia sem sentido nenhum pro usuário.
                        inserirRegistro($pdo, $linha['valores']);
                        $importados++;
                    }
                    $resumoImportacao = [
                        'importados' => $importados,
                        'pulados'    => $totalInvalidas,
                        'total'      => $totalLinhas,
                    ];
                    $csvConteudo = '';
                    $preview = null;
                }
            } else {
                $preview = [
                    'linhas'         => array_slice($linhasValidadas, 0, 200),
                    'total'          => $totalLinhas,
                    'totalValidas'   => $totalValidas,
                    'totalInvalidas' => $totalInvalidas,
                    'truncado'       => $totalLinhas > 200,
                ];
            }
        }
    }
}

$tituloPagina = 'Importar Histórico — PitStop BR';
$mostrarVoltar = true;
require __DIR__ . '/includes/header.php';
?>

<div class="px-1">
    <h6 class="text-muted mb-1 px-1">Importar Histórico (CSV)</h6>
    <p class="text-muted small px-1 mb-3">
        Importe registros a partir de um arquivo no mesmo formato do CSV exportado em Relatórios
        (<a href="relatorios.php">exporte um exemplo por lá</a> pra ver o formato certo).
        Todas as linhas vão pro veículo que você escolher abaixo.
    </p>

    <?php if ($erros): ?>
    <div class="alert alert-danger py-2">
        <ul class="mb-0 ps-3 small">
            <?php foreach ($erros as $erro): ?><li><?= h($erro) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($resumoImportacao !== null): ?>
    <div class="alert alert-success py-2">
        <p class="mb-0 small">
            <strong><?= (int) $resumoImportacao['importados'] ?></strong> de <strong><?= (int) $resumoImportacao['total'] ?></strong> linha(s) importada(s) com sucesso.
            <?php if ($resumoImportacao['pulados'] > 0): ?>
                <?= (int) $resumoImportacao['pulados'] ?> linha(s) puladas por erro.
            <?php endif; ?>
        </p>
    </div>
    <a href="index.php" class="btn btn-primary w-100 mb-4">Ver registros importados</a>
    <?php endif; ?>

    <?php if ($preview === null): ?>
    <form method="post" action="importar.php" id="formImportarCsv" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="acao" value="analisar">
        <input type="hidden" name="csv_conteudo" id="campoCsvConteudo">

        <div class="mb-3">
            <label class="form-label">Veículo de destino</label>
            <select name="veiculo_id" class="form-select form-select-lg" required>
                <option value="">Selecione...</option>
                <?php foreach ($veiculos as $v): ?>
                <option value="<?= (int) $v['id'] ?>" <?= $veiculoIdEscolhido === (int) $v['id'] ? 'selected' : '' ?>>
                    <?= h($v['nome']) ?> (<?= h($v['tipo']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Arquivo CSV</label>
            <input type="file" accept=".csv,text/csv" id="campoArquivoCsv" class="form-control form-control-lg" required>
        </div>

        <div class="mb-4">
            <label class="form-label">Se alguma linha tiver erro</label>
            <div class="form-check">
                <input type="radio" name="modo" value="pular" id="modoPular" class="form-check-input" <?= $modo === 'pular' ? 'checked' : '' ?>>
                <label class="form-check-label small" for="modoPular">Pular só as linhas com erro e importar o resto</label>
            </div>
            <div class="form-check">
                <input type="radio" name="modo" value="abortar" id="modoAbortar" class="form-check-input" <?= $modo === 'abortar' ? 'checked' : '' ?>>
                <label class="form-check-label small" for="modoAbortar">Abortar tudo se houver qualquer erro</label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100 mb-4">
            <i class="bi bi-search me-1"></i>Analisar Arquivo
        </button>
    </form>
    <?php else: ?>
    <div class="alert alert-info py-2 mb-3">
        <p class="mb-0 small">
            <strong><?= (int) $preview['total'] ?></strong> linha(s) encontrada(s):
            <strong class="text-success"><?= (int) $preview['totalValidas'] ?></strong> válida(s),
            <strong class="<?= $preview['totalInvalidas'] > 0 ? 'text-danger' : '' ?>"><?= (int) $preview['totalInvalidas'] ?></strong> com erro.
            <?php if ($preview['truncado']): ?>Mostrando as 200 primeiras linhas abaixo.<?php endif; ?>
        </p>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body p-0" style="overflow-x: auto; max-height: 400px;">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="small ps-3">Linha</th>
                        <th class="small">Status</th>
                        <th class="small">Detalhe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview['linhas'] as $l): ?>
                    <tr>
                        <td class="small ps-3"><?= (int) $l['numero'] ?></td>
                        <td class="small">
                            <?php if ($l['ok']): ?>
                                <span class="badge bg-success">OK</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Erro</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $l['ok'] ? h(implode(' · ', $l['bruta'])) : h((string) $l['erro']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($preview['totalInvalidas'] > 0 && $modo === 'abortar'): ?>
    <div class="alert alert-warning py-2 small mb-3">
        Existem linhas com erro e o modo selecionado foi "abortar tudo" — nada será importado até você trocar pra "pular linhas com erro" ou corrigir o arquivo.
    </div>
    <?php endif; ?>

    <form method="post" action="importar.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="acao" value="confirmar">
        <input type="hidden" name="veiculo_id" value="<?= (int) $veiculoIdEscolhido ?>">
        <input type="hidden" name="modo" value="<?= h($modo) ?>">
        <input type="hidden" name="csv_conteudo" value="<?= h($csvConteudo) ?>">
        <button type="submit" class="btn btn-primary btn-lg w-100 mb-2" <?= ($preview['totalInvalidas'] > 0 && $modo === 'abortar') ? 'disabled' : '' ?>>
            <i class="bi bi-check-lg me-1"></i>Confirmar Importação
            <?php if ($preview['totalInvalidas'] > 0 && $modo === 'pular'): ?>
            (<?= (int) $preview['totalValidas'] ?> linha(s))
            <?php endif; ?>
        </button>
        <a href="importar.php" class="btn btn-outline-secondary w-100 mb-4">Cancelar</a>
    </form>
    <?php endif; ?>
</div>

<script src="assets/js/importar.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
