<?php
declare(strict_types=1);

/**
 * Recebe relatórios de violação de CSP (ver Content-Security-Policy
 * report-uri em docker/php/security.conf). O navegador manda esse POST
 * sozinho, sem cookie de sessão nem token CSRF — por isso este endpoint,
 * ao contrário de todo o resto da API, não passa por bootstrap.php/exigirLogin/
 * csrfValidar. Só loga (nunca falha) — é telemetria, não uma ação de usuário.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// application/csp-report (legado) ou application/reports+json (Reporting API
// nova) — ambos são JSON no corpo. Corpo limitado pelo post_max_size do
// php.ini; não há necessidade de limite adicional aqui.
$corpo = file_get_contents('php://input');
if ($corpo !== false && $corpo !== '') {
    error_log('[csp-report] ' . substr($corpo, 0, 2000));
}

http_response_code(204);
