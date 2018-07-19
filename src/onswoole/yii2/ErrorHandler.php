<?php
/**
 * @author Pan Wenbin <panwenbin@gmail.com>
 */

namespace onswoole\yii2;


use Yii;
use yii\base\ErrorException;
use yii\base\ExitException;
use yii\helpers\VarDumper;

class ErrorHandler extends \yii\web\ErrorHandler
{
    private $_memoryReserve;
    private $_hhvmException;

    public function register()
    {
        ini_set('display_errors', false);
        if (defined('HHVM_VERSION')) {
            set_error_handler([$this, 'handleHhvmError']);
        } else {
            set_error_handler([$this, 'handleError']);
        }
        if ($this->memoryReserveSize > 0) {
            $this->_memoryReserve = str_repeat('x', $this->memoryReserveSize);
        }
    }

    public function handleException($exception)
    {
        if ($exception instanceof ExitException) {
            return;
        }

        $this->exception = $exception;

        // disable error capturing to avoid recursive errors while handling exceptions
        //$this->unregister();

        try {
            $this->logException($exception);

            if ($this->discardExistingOutput) {
                $this->clearOutput();
            }

            $this->renderException($exception);

            \Yii::$app->getResponse()->statusCode = $exception->getCode() ?: 500;
            \onswoole\WorkerCallback::afterRequest();

            if (!YII_ENV_TEST) {
                \Yii::getLogger()->flush(true);
                if (defined('HHVM_VERSION')) {
                    flush();
                }
            }
            return;
        } catch (\Exception $e) {
            // an other exception could be thrown while displaying the exception
            $this->handleFallbackExceptionMessage($e, $exception);
        } catch (\Throwable $e) {
            // additional check for \Throwable introduced in PHP 7
            $this->handleFallbackExceptionMessage($e, $exception);
        }

        $this->exception = null;
    }

    protected function handleFallbackExceptionMessage($exception, $previousException)
    {
        $msg = "An Error occurred while handling another error:\n";
        $msg .= (string)$exception;
        $msg .= "\nPrevious exception:\n";
        $msg .= (string)$previousException;
        if (\Yii::$app) {
            $response = \Yii::$app->response;
            if ($response instanceof \onswoole\yii2\Response) {
                /* @var \onswoole\yii2\Response $response */
                if (!$response->isSent) {
                    $response->swoole_http_response->status(500);
                    $response->swoole_http_response->header('Content-Type', 'text/html; charset=UTF-8');
                    if (YII_DEBUG) {
                        $response->swoole_http_response->end('<pre>' . htmlspecialchars($msg, ENT_QUOTES, Yii::$app->charset) . '</pre>');
                        \Yii::$app->getResponse()->statusCode = 500;
                        \onswoole\WorkerCallback::afterRequest();
                    } else {
                        $response->swoole_http_response->end('An internal server error occurred.');
                    }
                }
            }
        }
        $msg .= "\n\$_SERVER = " . VarDumper::export($_SERVER);
        error_log($msg);
        if (defined('HHVM_VERSION')) {
            flush();
        }
    }

    /**
     * @param int $code
     * @param string $message
     * @param string $file
     * @param int $line
     * @return bool
     * @throws ErrorException
     */
    public function handleError($code, $message, $file, $line)
    {
        if (error_reporting() & $code) {
            // load ErrorException manually here because autoloading them will not work
            // when error occurs while autoloading a class
            if (!class_exists('yii\\base\\ErrorException', false)) {
                require_once(__DIR__ . '/../../../../../../vendor/yiisoft/yii2/base/ErrorException.php');
            }
            $exception = new ErrorException($message, $code, $code, $file, $line);

            // in case error appeared in __toString method we can't throw any exception
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            array_shift($trace);
            foreach ($trace as $frame) {
                if ($frame['function'] === '__toString') {
                    $this->handleException($exception);
                    if (defined('HHVM_VERSION')) {
                        flush();
                    }
                }
            }

            throw $exception;
        }
        return false;
    }

    public function handleFatalError()
    {
        unset($this->_memoryReserve);

        // load ErrorException manually here because autoloading them will not work
        // when error occurs while autoloading a class
        if (!class_exists('yii\\base\\ErrorException', false)) {
            require_once(__DIR__ . '/../../../../../../vendor/yiisoft/yii2/base/ErrorException.php');
        }

        $error = error_get_last();

        if (ErrorException::isFatalError($error)) {
            if (!empty($this->_hhvmException)) {
                $exception = $this->_hhvmException;
            } else {
                $exception = new ErrorException($error['message'], $error['type'], $error['type'], $error['file'], $error['line']);
            }
            $this->exception = $exception;

            $this->logException($exception);

            if ($this->discardExistingOutput) {
                $this->clearOutput();
            }
            $this->renderException($exception);

            // need to explicitly flush logs because exit() next will terminate the app immediately
            Yii::getLogger()->flush(true);
            if (defined('HHVM_VERSION')) {
                flush();
            }
        }
    }
}