<?php
/**
 * @author Pan Wenbin <panwenbin@gmail.com>
 */

namespace onswoole\yii2;


use Yii;
use yii\base\InvalidConfigException;
use yii\redis\Connection;
use yii\redis\Session;

class RedisSession extends Session
{
    private $_session = [];
    private $_cookieParams = ['httponly' => true];

    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        if (is_string($this->redis)) {
            $this->redis = Yii::$app->get($this->redis);
        } elseif (is_array($this->redis)) {
            if (!isset($this->redis['class'])) {
                $this->redis['class'] = Connection::className();
            }
            $this->redis = Yii::createObject($this->redis);
        }
        if (!$this->redis instanceof Connection) {
            throw new InvalidConfigException("Session::redis must be either a Redis connection instance or the application component ID of a Redis connection.");
        }
        if ($this->keyPrefix === null) {
            $this->keyPrefix = substr(md5(Yii::$app->id), 0, 5);
        }
        if ($this->getIsActive()) {
            Yii::warning('Session is already started', __METHOD__);
            $this->updateFlashCounters();
        }
        $this->open();
    }

    public function open()
    {
        if ($this->getIsActive()) {
            return;
        }

        $this->start();

        if ($this->getIsActive()) {
            Yii::info('Session started', __METHOD__);
            $this->updateFlashCounters();
        } else {
            $error = error_get_last();
            $message = isset($error['message']) ? $error['message'] : 'Failed to start session.';
            Yii::error($message, __METHOD__);
        }
    }

    protected function updateFlashCounters()
    {
        $counters = $this->get($this->flashParam, []);
        if (is_array($counters)) {
            foreach ($counters as $key => $count) {
                if ($count > 0) {
                    unset($counters[$key], $this->_session[$key]);
                } elseif ($count == 0) {
                    $counters[$key]++;
                }
            }
            $this->_session[$this->flashParam] = $counters;
        } else {
            // fix the unexpected problem that flashParam doesn't return an array
            unset($this->_session[$this->flashParam]);
        }
    }

    private function start()
    {
        $id = $this->getId();
        $this->_session = json_decode($this->readSession($id), true);
        $this->_sessionStatus = PHP_SESSION_ACTIVE;
    }

    public function persist()
    {
        $id = $this->getId();
        $this->writeSession($id, json_encode($this->_session));
        $this->redis->close();
    }

    private $_sessionStatus;

    public function getIsActive()
    {
        return $this->_sessionStatus === PHP_SESSION_ACTIVE;
    }

    public function close()
    {
        $this->_sessionStatus = PHP_SESSION_NONE;
    }

    private $_hasSessionId;

    private $_sessionId;

    public function getId()
    {
        if ($this->getHasSessionId() === false) {
            $this->_sessionId = $this->regenerateID();
        }
        return $this->_sessionId;
    }

    public function setId($value)
    {
        $this->_sessionId = $value;
    }

    public function getHasSessionId()
    {
        if ($this->_hasSessionId === null) {
            $name = $this->getName();
            $request = Yii::$app->getRequest();
            if ($request instanceof \onswoole\yii2\Request && !empty($request->swoole_http_request->cookie[$name]) && ini_get('session.use_cookies')) {
                $this->_sessionId = $request->swoole_http_request->cookie[$name];
            } elseif (!ini_get('session.use_only_cookies') && ini_get('session.use_trans_sid')) {
                $this->_sessionId = $request->get($name);
            }
        }
        $this->_hasSessionId = $this->_sessionId !== null;
        return $this->_hasSessionId;
    }

    public function setHasSessionId($value)
    {
        $this->_hasSessionId = $value;
    }

    public function destroy()
    {
        if ($this->getId()) {
            $this->removeAll();
        }
    }

    public function getUseCustomStorage()
    {
        return true;
    }

    public function regenerateID($deleteOldSession = false)
    {
        if ($deleteOldSession) {
            $this->destroy();
        }
        if (version_compare(PHP_VERSION, '7.1', '<')) {
            $request = Yii::$app->getRequest();
            $remoteAddr = $request->getUserIP();
            $this->_sessionId = md5($remoteAddr . microtime() . rand(0, 100000));
        } else {
            $this->_sessionId = session_create_id();
        }
        $this->sendSessionId();
    }

    private $_sessionName = 'PHPSESSID';

    public function getName()
    {
        return $this->_sessionName;
    }

    public function setName($value)
    {
        $this->_sessionName = $value;
    }


    public function getCount()
    {
        return count($this->_session);
    }

    public function get($key, $defaultValue = null)
    {
        return isset($this->_session[$key]) ? $this->_session[$key] : $defaultValue;
    }

    public function set($key, $value)
    {
        $this->_session[$key] = $value;
    }

    public function remove($key)
    {
        if (isset($this->_session[$key])) {
            $value = $this->_session[$key];
            unset($this->_session[$key]);
            return $value;
        } else {
            return null;
        }
    }

    public function removeAll()
    {
        $this->_session = [];
    }

    public function has($key)
    {
        return isset($this->_session[$key]);
    }

    public function getFlash($key, $defaultValue = null, $delete = false)
    {
        $counters = $this->get($this->flashParam, []);
        if (isset($counters[$key])) {
            $value = $this->get($key, $defaultValue);
            if ($delete) {
                $this->removeFlash($key);
            } elseif ($counters[$key] < 0) {
                // mark for deletion in the next request
                $counters[$key] = 1;
                $this->_session[$this->flashParam] = $counters;
            }

            return $value;
        } else {
            return $defaultValue;
        }
    }

    public function getAllFlashes($delete = false)
    {
        $counters = $this->get($this->flashParam, []);
        $flashes = [];
        foreach (array_keys($counters) as $key) {
            if (array_key_exists($key, $this->_session)) {
                $flashes[$key] = $this->_session[$key];
                if ($delete) {
                    unset($counters[$key], $this->_session[$key]);
                } elseif ($counters[$key] < 0) {
                    // mark for deletion in the next request
                    $counters[$key] = 1;
                }
            } else {
                unset($counters[$key]);
            }
        }

        $this->_session[$this->flashParam] = $counters;

        return $flashes;
    }

    public function setFlash($key, $value = true, $removeAfterAccess = true)
    {
        $counters = $this->get($this->flashParam, []);
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $this->_session[$key] = $value;
        $this->_session[$this->flashParam] = $counters;
    }

    public function addFlash($key, $value = true, $removeAfterAccess = true)
    {
        $counters = $this->get($this->flashParam, []);
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $this->_session[$this->flashParam] = $counters;
        if (empty($this->_session[$key])) {
            $this->_session[$key] = [$value];
        } else {
            if (is_array($this->_session[$key])) {
                $this->_session[$key][] = $value;
            } else {
                $this->_session[$key] = [$this->_session[$key], $value];
            }
        }
    }

    public function removeFlash($key)
    {
        $counters = $this->get($this->flashParam, []);
        $value = isset($this->_session[$key], $counters[$key]) ? $this->_session[$key] : null;
        unset($counters[$key], $this->_session[$key]);
        $this->_session[$this->flashParam] = $counters;

        return $value;
    }

    public function removeAllFlashes()
    {
        $counters = $this->get($this->flashParam, []);
        foreach (array_keys($counters) as $key) {
            unset($this->_session[$key]);
        }
        unset($this->_session[$this->flashParam]);
    }

    public function offsetExists($offset)
    {
        $this->open();

        return isset($this->_session[$offset]);
    }

    public function offsetGet($offset)
    {
        $this->open();

        return isset($this->_session[$offset]) ? $this->_session[$offset] : null;
    }

    public function offsetSet($offset, $item)
    {
        $this->open();
        $this->_session[$offset] = $item;
    }

    public function offsetUnset($offset)
    {
        $this->open();
        unset($this->_session[$offset]);
    }

    private function sendSessionId()
    {
        $response = Yii::$app->getResponse();
        if ($response instanceof \onswoole\yii2\Response) {
            $cookieParams = $this->getCookieParams();
            if (isset($cookieParams['lifetime'], $cookieParams['path'], $cookieParams['domain'], $cookieParams['secure'], $cookieParams['httponly'])) {
                $expire = $cookieParams['lifetime'] ? time() + $cookieParams['lifetime'] : 0;
                $response->swoole_http_response->cookie($this->getName(), $this->_sessionId, $expire, $cookieParams['path'], $cookieParams['domain'], $cookieParams['secure'], $cookieParams['httponly']);
            } else {
                $response->swoole_http_response->cookie($this->getName(), $this->_sessionId);
            }
        }
    }

}