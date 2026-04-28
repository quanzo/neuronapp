<?php

require __DIR__ . '/../vendor/autoload.php';

use app\modules\neuron\classes\command\HelloCommand;
use app\modules\neuron\classes\command\RuwikiCommand;
use app\modules\neuron\classes\command\SimpleMessageCommand;
use app\modules\neuron\classes\command\TodolistCommand;
use app\modules\neuron\classes\command\ClearAllSessionsCommand;
use app\modules\neuron\classes\command\ClearSessionCommand;
use app\modules\neuron\classes\command\OrchestrateCommand;
use app\modules\neuron\classes\command\WikiCommand;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\console\TimedConsoleApplication;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\logger\FileLogger;
use app\modules\neuron\classes\producers\AgentProducer;
use app\modules\neuron\classes\producers\SkillProducer;
use app\modules\neuron\classes\producers\TodoListProducer;
define('APP_ID', 'neuronapp');

$app = new TimedConsoleApplication(APP_ID, '0.0.1');
$arDirs = [];

/**
 * Директория в папке старта
 * 
 * Если в папке старта приложения есть папка `.neauronapp` то считаем эту папку приоритетной
 */
$arDirs['APP_START_DIR'] = !defined('APP_START_DIR') ? getcwd() : APP_START_DIR;
$arDirs['APP_CFG_DIR'] = $arDirs['APP_START_DIR'] . DIRECTORY_SEPARATOR . '.' . APP_ID;
$arDirs = array_filter($arDirs, fn($val) => is_dir($val));

/**
 * Директория приложения в домашней папке пользователя
 */
if (!defined('APP_WORK_DIR')) {
    $homeDir = getenv('HOME');

    if ($homeDir === false || $homeDir === '') {
        $homeDir = $_SERVER['HOME'] ?? '';
    }

    if ($homeDir === '' || !is_dir($homeDir)) {
        fwrite(STDERR, "Unable to determine user home directory.\n");
        exit(1);
    }

    $workDir = $homeDir . DIRECTORY_SEPARATOR . '.' . APP_ID;
    if (!is_dir($workDir)) {
        if (!mkdir($workDir, 0777, true) && !is_dir($workDir)) {
            fwrite(STDERR, sprintf("Unable to create application directory: %s\n", $workDir));
            exit(1);
        }
    }
} else {
    $workDir = APP_WORK_DIR;
    if (!is_dir($workDir)) {
        fwrite(STDERR, sprintf("Unable to finde application directory: %s\n", $workDir));
        exit(1);
    }
}
$arDirs['APP_WORK_DIR'] = $workDir;

// создаем директории для работы приложения в домашней папке приложения
foreach ([
        SkillProducer::getStorageDirName(),
        TodoListProducer::getStorageDirName(),
        ConfigurationApp::getSessionDirName(),
        AgentProducer::getStorageDirName(),
        ConfigurationApp::getLogDirName(),
        ConfigurationApp::getStoreDirName(),
        ConfigurationApp::getMindDirName()
    ] as $subDir
) {
    $path = $workDir . DIRECTORY_SEPARATOR . $subDir;
    if (!is_dir($path)) {
        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            fwrite(STDERR, sprintf("Unable to create subdirectory: %s\n", $path));
            exit(1);
        }
    }
}

// приоритет директорий приложения
$dirPriority = new DirPriority($arDirs);

try {
    ConfigurationApp::init($dirPriority);
} catch (\Throwable $e) {
    fwrite(STDERR, "[CONFIG ERROR] " . $e->getMessage() . "\n");
    exit(1);
}

// Регистрируем команды
$app->add(new HelloCommand());
$app->add(new SimpleMessageCommand());
$app->add(new TodolistCommand());
$app->add(new WikiCommand());
$app->add(new RuwikiCommand());
$app->add(new OrchestrateCommand());
$app->add(new ClearSessionCommand());
$app->add(new ClearAllSessionsCommand());

// Можно также добавить встроенную команду list, которая уже есть в Symfony,
// поэтому отдельная HelpCommand не требуется, но при желании можно добавить.

$app->run();
