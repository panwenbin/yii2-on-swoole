<?php
/**
 * @author Pan Wenbin <panwenbin@gmail.com>
 */

namespace onswoole;


use onswoole\yii2\WebApplication;
use swoole_http_request;
use swoole_http_response;

class WorkerCallback
{
    public static function httpRequest(swoole_http_request $request, swoole_http_response $response)
    {
        static::resetGlobalVariables($request);
        $app = \Yii::$app;
        $coreComponents = $app->coreComponents();
        $rebuildComponents = array_intersect_key($app->rawConfig['components'], ['user' => true, 'request' => true, 'response' => true]);
        foreach ($rebuildComponents as $componentName => $componentConfig) {
            $config = array_merge($coreComponents[$componentName] ?? [], $app->rawConfig['components'][$componentName]);
            $app->set($componentName, $config);
        }
        $app->request->swoole_http_request = $request;
        $app->response->swoole_http_response = $response;
        $app->run();
        WebApplication::reset();
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
        \Yii::$app->log->logger->flush(true);
        if (YII_DEBUG) {
            $remote_addr = $request->server['remote_addr'];
            $request_method = $request->server['request_method'];
            $request_uri = $request->server['request_uri'];
            if (isset($request->server['query_string'])) {
                $request_uri .= '?';
                $request_uri .= $request->server['query_string'];
            }
            $status = \Yii::$app->getResponse()->statusCode;
            if ($status < 300) {
                $colorStatus = "[\033[32m{$status}\033[0m]";
            } elseif ($status < 400) {
                $colorStatus = "[\033[34m{$status}\033[0m]";
            } else {
                $colorStatus = "[\033[31m{$status}\033[0m]";
            }

            echo date('Y-m-d H:i:s'), " [\033[32m{$request_method}\033[0m] ", $request_uri, ' ', $remote_addr, ' ', $colorStatus, ' ', round(memory_get_usage() / 1024 / 1024, 1), 'MB ', "\r\n";
        }
    }
}