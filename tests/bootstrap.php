<?php

declare(strict_types=1);

// Bootstrap do PHPUnit: carrega o autoload do Composer (instalado em
// src/vendor/, ver src/composer.json) e as funções puras/CRUD que os testes
// exercitam diretamente (elas não são autoloadadas — são funções soltas,
// no mesmo padrão de inclusão via require_once usado pelas páginas em src/).
//
// Não usamos config/bootstrap.php (o bootstrap "de verdade" da aplicação):
// ele chama session_start(), define cookies e já abre uma conexão PDO via
// getenv(DB_HOST) fixo — os testes precisam controlar isso caso a caso
// (alguns não têm banco nenhum, outros usam um banco de teste dedicado).
require_once __DIR__ . '/../src/vendor/autoload.php';
require_once __DIR__ . '/../src/includes/functions.php';
require_once __DIR__ . '/../src/config/csrf.php';
require_once __DIR__ . '/../src/config/auth.php';

// csrf.php e auth.php leem/escrevem em $_SESSION diretamente (sem chamar
// session_start() — isso é feito por config/bootstrap.php nas páginas reais).
// Em CLI, sem sessão ativa, $_SESSION não existe: inicializa como array vazio
// pra essas funções poderem ler/gravar normalmente nos testes.
$_SESSION = [];
