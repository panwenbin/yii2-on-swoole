<?php
/**
 * @author Pan Wenbin <panwenbin@gmail.com>
 */

namespace onswoole\yii2;


use yii\base\ExitException;
use yii\web\Application;

class WebApplication extends Application
{
    public $rawConfig;

    /**
     * @return bool|int
     * @throws ExitException
     * @throws \yii\base\ErrorException
     */
    public function run()
    {
        try {
            $this->state = self::STATE_BEFORE_REQUEST;
            $this->trigger(self::EVENT_BEFORE_REQUEST);

            $this->state = self::STATE_HANDLING_REQUEST;
            $response = $this->handleRequest($this->getRequest());

            $this->state = self::STATE_AFTER_REQUEST;
            $this->trigger(self::EVENT_AFTER_REQUEST);

            $this->state = self::STATE_SENDING_RESPONSE;
            $response->send();

            $this->state = self::STATE_END;

            return $response->exitStatus;
        } catch (ExitException $e) {
            $this->end($e->statusCode, isset($response) ? $response : null);
            return $e->statusCode;
        } catch (\Exception $e) {
            \Yii::$app->getErrorHandler()->handleException($e);
            return false;
        } catch (\Error $e) {
            \Yii::$app->getErrorHandler()->handleException($e);
            return false;
        } catch (\Throwable $e) {
            \Yii::$app->getErrorHandler()->handleError($e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * @param int $status
     * @param null $response
     * @throws ExitException
     */
    public function end($status = 0, $response = null)
    {
        if ($this->state === self::STATE_BEFORE_REQUEST || $this->state === self::STATE_HANDLING_REQUEST) {
            $this->state = self::STATE_AFTER_REQUEST;
            $this->trigger(self::EVENT_AFTER_REQUEST);
        }

        if ($this->state !== self::STATE_SENDING_RESPONSE && $this->state !== self::STATE_END) {
            $this->state = self::STATE_END;
            $response = $response ?: $this->getResponse();
            $response->send();
        }

        throw new ExitException($status);
    }

    public static function reset()
    {
        \yii\base\Event::offAll();
        \yii\base\Widget::$stack = [];
        \yii\base\Widget::$counter = 0;
        \yii\caching\Dependency::resetReusableData();
        \yii\web\UploadedFile::reset();
        \Yii::$app->errorHandler->unregister();
        \Yii::getLogger()->dispatcher = null;
        \Yii::setLogger(null);

        if (isset(\Yii::$app->loadedModules['yii\debug\Module'])) {
            $debug = \Yii::$app->loadedModules['yii\debug\Module'];
            $debug->logTarget->module = null;
            $debug->logTarget = null;
            foreach ($debug->panels as $panel) {
                $panel->module = null;
            }
            $debug->panels = [];
        }

        if (\Yii::$app->requestedAction) {
            \Yii::$app->requestedAction->controller = null;
            \Yii::$app->requestedAction = null;
        }
        if (\Yii::$app->controller) {
            foreach (\Yii::$app->getModules() as $id => $module) {
                if ($module instanceof \yii\base\Module) {
                    $module->module = null;
                }
                \Yii::$app->setModule($id, null);
            }
            \Yii::$app->loadedModules = [];
            \Yii::$app->controller->detachBehaviors();
            \Yii::$app->controller->module = null;
            \Yii::$app->controller->action = null;
            \Yii::$app->controller = null;
        }
        foreach (\Yii::$app->components as $id => $component) {
            \Yii::$app->clear($id);
        }
        \Yii::$container = new \yii\di\Container();
    }
}