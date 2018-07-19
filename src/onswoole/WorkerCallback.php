<?php
/**
 * @author Pan Wenbin <panwenbin@gmail.com>
 */

namespace onswoole;


use swoole_http_request;
use swoole_http_response;

class WorkerCallback
{
    public static function httpRequest($app, swoole_http_request $request, swoole_http_response $response)
    {
        static::resetGlobalVariables($request);
        $appRoot = realpath(__DIR__ . '/../../../../../');

        require_once($appRoot . '/vendor/autoload.php');
        require_once($appRoot . '/vendor/yiisoft/yii2/Yii.php');

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
        $config['components']['request']['swoole_http_request'] = $request;
        $config['components']['request']['scriptFile'] = $scriptFile;
        $config['components']['response']['class'] = '\onswoole\yii2\Response';
        $config['components']['response']['swoole_http_response'] = $response;
        $config['components']['session']['class'] = '\onswoole\yii2\RedisSession';
        $config['components']['log']['logger']['class'] = '\onswoole\yii2\Logger';
        $config['components']['errorHandler']['class'] = '\onswoole\yii2\ErrorHandler';
        $config['on afterRequest'] = [self::class, 'afterRequest'];

        (new \onswoole\yii2\WebApplication($config))->run();

    }

    /**
     * @param swoole_http_request $request
     */
    protected static function resetGlobalVariables(swoole_http_request $request)
    {
        if (isset($request->files)) {
            $files = $request->files;
            foreach ($files as $k => $v) {
                if (isset($v['name'])) {
                    $_FILES = $files;
                    break;
                }
                foreach ($v as $key => $val) {
                    $_FILES[$k]['name'][$key] = $val['name'];
                    $_FILES[$k]['type'][$key] = $val['type'];
                    $_FILES[$k]['tmp_name'][$key] = $val['tmp_name'];
                    $_FILES[$k]['size'][$key] = $val['size'];
                    if (isset($val['error'])) $_FILES[$k]['error'][$key] = $val['error'];
                }
            }
        }
        $_GET = $request->get ?? [];
        $_POST = $request->post ?? [];
        $_COOKIE = $request->cookie ?? [];
        $server = $request->server ?? [];
        $header = $request->header ?? [];
        foreach ($server as $key => $value) {
            $_SERVER[strtoupper($key)] = $value;
        }
        foreach ($header as $key => $value) {
            $_SERVER['HTTP_' . strtoupper($key)] = $value;
        }
        $_SERVER['SERVER_SOFTWARE'] = "swoole/" . SWOOLE_VERSION;
    }

    public static function afterRequest()
    {
        $request = \Yii::$app->request->swoole_http_request;
        \Yii::$app->session->persist();
        $logger = \Yii::getLogger();
        $logger->flush(true);
        if (YII_DEBUG) {
            $remote_addr = $request->server['remote_addr'];
            $request_method = $request->server['request_method'];
            $request_uri = $request->server['request_uri'];
            if (isset($request->server['query_string'])) {
                $request_uri .= '?';
                $request_uri .= $request->server['query_string'];
            }
            echo date('Y-m-d H:i:s'), " [\033[32m{$request_method}\033[0m] ", $request_uri, ' ', $remote_addr, ' ';
            $status = \Yii::$app->getResponse()->statusCode;
            if ($status < 300) {
                echo "[\033[32m{$status}\033[0m]";
            } elseif ($status < 400) {
                echo "[\033[34m{$status}\033[0m]";
            } else {
                echo "[\033[31m{$status}\033[0m]";
            }

            echo "\r\n";
        }
    }
}