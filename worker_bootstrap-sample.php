<?php
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

$appRoot = __DIR__;

require($appRoot . '/vendor/autoload.php');
require($appRoot . '/vendor/yiisoft/yii2/Yii.php');

if ($app) {
    require($appRoot . '/common/config/bootstrap.php');
    require($appRoot . "/{$app}/config/bootstrap.php");

    $config = \yii\helpers\ArrayHelper::merge(
        require($appRoot . '/common/config/main.php'),
        require($appRoot . '/common/config/main-local.php'),
        require($appRoot . "/{$app}/config/main.php"),
        require($appRoot . "/{$app}/config/main-local.php")
    );
    $scriptFile = $appRoot . "/{$app}/web/index.php";
} else {
    $config = require($appRoot . '/config/web.php');
    $scriptFile = $appRoot . "/web/index.php";
}

$config['components']['request']['class'] = '\onswoole\yii2\Request';
$config['components']['request']['scriptFile'] = $scriptFile;
$config['components']['response']['class'] = '\onswoole\yii2\Response';
$config['components']['session']['class'] = '\onswoole\yii2\RedisSession';
$config['components']['log']['logger']['class'] = '\onswoole\yii2\Logger';
$config['components']['errorHandler']['class'] = '\onswoole\yii2\ErrorHandler';
$config['components']['db']['commandClass'] = '\onswoole\yii2\DbCommand';
$config['on afterRequest'] = ['\onswoole\WorkerCallback', 'afterRequest'];

$app = new \onswoole\yii2\WebApplication($config);
$app->rawConfig = $config;

