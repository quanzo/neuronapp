<?php

$pharFile = 'neuronapp.phar';

if (file_exists($pharFile)) {
    unlink($pharFile);
}

try {
    $phar = new Phar($pharFile, 0, $pharFile);
    $phar->setSignatureAlgorithm(Phar::SHA256);
    $phar->buildFromDirectory(__DIR__ . '/../', '/^(?!.*(?:\.git|tests|build-phar\.php|.*\.phar)).*$/i');
    $phar->setDefaultStub('bin/console', 'bin/console');

    echo "Phar-архив успешно создан: {$pharFile}\n";
} catch (Exception $e) {
    echo "Ошибка при создании phar: {$e->getMessage()}\n";
}
