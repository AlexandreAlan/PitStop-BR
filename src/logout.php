<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

logoutUsuario();
header('Location: login.php');
exit;
