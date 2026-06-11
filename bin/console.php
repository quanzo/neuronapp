<?php

require __DIR__ . '/../vendor/autoload.php';

use app\modules\neuron\command\ClearAllSessionsCommand;
use app\modules\neuron\command\ClearSessionCommand;
use app\modules\neuron\command\HelloCommand;
use app\modules\neuron\command\MindSessionSummaryCommand;
use app\modules\neuron\command\OrchestrateCommand;
use app\modules\neuron\command\RuwikiCommand;
use app\modules\neuron\command\SimpleMessageCommand;
use app\modules\neuron\command\TodolistCommand;
use app\modules\neuron\command\WikiCommand;
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
 */
$arDirs = [
    'APP_START_DIR' => getenv('APP_START_DIR'), // директрия старта приложения (консольной команды)
    'APP_CFG_DIR'   => getenv('APP_CFG_DIR')  , // директория с настройками приложения в директории старта
    'APP_WORK_DIR'  => getenv('APP_WORK_DIR') , // директория пользователя с настройками приложения
];
/**
 * Ассоциативный массив с путями к основным директориям приложения.
 *
 * @var array{
 *     APP_START_DIR: string|false, // директория старта приложения (консольной команды)
 *     APP_CFG_DIR:   string|false, // директория с настройками приложения в директории старта
 *     APP_WORK_DIR:  string|false, // директория пользователя с настройками приложения
 * } $arDirs
 */

// используем null чтобы иметь возможность исключить некоторые директории из списка
foreach ($arDirs as $dirName => &$dirValue) {
    if ($dirValue === false) {
        $dirValue = null;
    }
}
unset($dirValue);
// константы с директориями
foreach ($arDirs as $dirName => &$dirValue) {
    /**
     * @var null|string $dirValue
     */
    if (is_null($dirValue) && defined($dirName)) {
        $dirValue = constant($dirName);
    }
}
unset($dirValue);
// значения типовые
if (is_null($arDirs['APP_START_DIR'])) {
    $arDirs['APP_START_DIR'] = getcwd();
}
if (is_null($arDirs['APP_CFG_DIR'])) {
    $arDirs['APP_CFG_DIR'] = $arDirs['APP_START_DIR'] . DIRECTORY_SEPARATOR . '.' . APP_ID;
}
if (is_null($arDirs['APP_WORK_DIR'])) {
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
    $arDirs['APP_WORK_DIR'] = $workDir;
} else {
     $workDir = $arDirs['APP_WORK_DIR'];
}
// уберем то что не является директориями
$arDirs = array_filter($arDirs, fn($val) => is_dir($val));

// создаем типовые директории в рабочей директории пользователя
$existWorkDir = !empty($arDirs['APP_WORK_DIR']);
if ($existWorkDir) {
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
}

if (empty($arDirs)) {
    fwrite(STDERR, "Критичная ошибка! Директории для работы приложения заданы не корректно! Список директорий пуст!\n");
    exit(1);
}
if (empty($arDirs['APP_START_DIR'])) {
    fwrite(STDERR, "Критичная ошибка! Директории старта должна быть! В ней работают инструменты bash!\n");
    exit(1);
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
$app->add(new MindSessionSummaryCommand());

// Можно также добавить встроенную команду list, которая уже есть в Symfony,
// поэтому отдельная HelpCommand не требуется, но при желании можно добавить.

$app->run();
