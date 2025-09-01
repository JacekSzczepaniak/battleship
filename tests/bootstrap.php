<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Domyślne wartości dla testów
$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'test';
$_SERVER['KERNEL_CLASS'] = $_SERVER['KERNEL_CLASS'] ?? $_ENV['KERNEL_CLASS'] ?? 'App\Kernel';

$root = dirname(__DIR__);
$dotenv = new Dotenv();
$dotenv->usePutenv();

// Załaduj bazowe .env (ustawia APP_ENV, itp.)
$dotenv->bootEnv($root.'/.env');

// TERAZ nadpisz wartości wg kolejności (tylko jeśli pliki istnieją)
$env = $_SERVER['APP_ENV'] ?? 'test';
$paths = [
    "$root/.env",
    "$root/.env.local",
    "$root/.env.$env",
    "$root/.env.$env.local",
];

foreach ($paths as $p) {
    if (is_file($p) && is_readable($p)) {
        // override istniejące zmienne — ważne dla DATABASE_URL
        $dotenv->overload($p);
    }
}
