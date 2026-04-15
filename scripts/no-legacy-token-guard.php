<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$token = chr(100).chr(101).chr(109).chr(111);
$pattern = '/\b'.$token.'\b/i';

$scanRoots = [
    'app',
    'bootstrap',
    'config',
    'database/factories',
    'database/migrations',
    'database/seeders',
    'lang',
    'resources',
    'routes',
    'tests',
    'scripts',
];

$matches = [];

$scanFile = static function (string $absolutePath, string $relativePath) use (&$matches, $pattern): void {
    $contents = @file_get_contents($absolutePath);
    if ($contents === false || ! mb_check_encoding($contents, 'UTF-8')) {
        return;
    }

    if (preg_match($pattern, $contents) === 1) {
        $matches[] = $relativePath;
    }
};

foreach ($scanRoots as $rootDir) {
    $absRoot = $root.DIRECTORY_SEPARATOR.$rootDir;
    if (! is_dir($absRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }
        $absolutePath = $file->getPathname();
        $relativePath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen($root))), '/');
        $scanFile($absolutePath, $relativePath);
    }
}

foreach (['composer.json', 'package.json'] as $topFile) {
    $abs = $root.DIRECTORY_SEPARATOR.$topFile;
    if (! is_file($abs)) {
        continue;
    }
    $scanFile($abs, $topFile);
}

if ($matches !== []) {
    fwrite(STDERR, "Legacy token found in files:\n");
    foreach ($matches as $path) {
        fwrite(STDERR, " - {$path}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Legacy token guard passed.\n");
