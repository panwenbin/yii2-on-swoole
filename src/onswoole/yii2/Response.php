<?php
/**
 * @author Pan Wenbin <panwenbin@gmail.com>
 */

namespace onswoole\yii2;


use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;
use yii\helpers\Url;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;
use yii\web\RangeNotSatisfiableHttpException;

class Response extends \yii\web\Response
{
    /**
     * @var \Swoole\Http\Response
     */
    public $swoole_http_response;

    public function setSwooleHttpResponse(\Swoole\Http\Response $swoole_http_reponse)
    {
        $this->swoole_http_response = $swoole_http_reponse;
    }

    /**
     * @var HeaderCollection
     */
    private $_headers;

    /**
     * Initializes this component.
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        if ($this->version === null) {
            $swoole_http_request = Yii::$app->getRequest();
            if ($swoole_http_request instanceof \Swoole\Http\Request) {
                $serverProtocol = $swoole_http_request->server['server_protocol'] ?? 'HTTP/1.1';
                if ($serverProtocol === 'HTTP/1.0') {
                    $this->version = '1.0';
                } else {
                    $this->version = '1.1';
                }
            }
        }
        if ($this->charset === null) {
            $this->charset = Yii::$app->charset;
        }
        $this->formatters = array_merge($this->defaultFormatters(), $this->formatters);
    }

    /**
     * Returns the header collection.
     * The header collection contains the currently registered HTTP headers.
     * @return HeaderCollection the header collection
     */
    public function getHeaders()
    {
        if ($this->_headers === null) {
            $this->_headers = new HeaderCollection;
        }
        return $this->_headers;
    }

    /**
     * Sends the response to the client.
     */
    public function send()
    {
        if ($this->isSent) {
            return;
        }
        $this->trigger(self::EVENT_BEFORE_SEND);
        $this->prepare();
        $this->trigger(self::EVENT_AFTER_PREPARE);
        $this->sendHeaders();
        $this->sendContent();
        $this->trigger(self::EVENT_AFTER_SEND);
        $this->isSent = true;
    }

    /**
     * Sends the response headers to the client
     */
    protected function sendHeaders()
    {
        $statusCode = $this->getStatusCode();
        $this->swoole_http_response->status($statusCode);
        if ($this->_headers) {
            $headers = $this->getHeaders();
            foreach ($headers as $name => $values) {
                // Swoole底层不允许设置相同$key的Http头
                $this->swoole_http_response->header($name, end($values));
            }
        }
        $this->sendCookies();
    }

    /**
     * Sends the cookies to the client.
     */
    protected function sendCookies()
    {
        if ($this->_cookies === null) {
            return;
        }
        $request = Yii::$app->getRequest();
        if ($request->enableCookieValidation) {
            if ($request->cookieValidationKey == '') {
                throw new InvalidConfigException(get_class($request) . '::cookieValidationKey must be configured with a secret key.');
            }
            $validationKey = $request->cookieValidationKey;
        }
        foreach ($this->getCookies() as $cookie) {
            $value = $cookie->value;
            if ($cookie->expire != 1 && isset($validationKey)) {
                $value = Yii::$app->getSecurity()->hashData(serialize([$cookie->name, $value]), $validationKey);
            }
            $this->swoole_http_response->cookie($cookie->name, $value, $cookie->expire, $cookie->path, $cookie->domain, $cookie->secure, $cookie->httpOnly);
        }
    }

    private $_cookies;

    /**
     * @return CookieCollection
     */
    public function getCookies()
    {
        if ($this->_cookies === null) {
            $this->_cookies = new CookieCollection;
        }
        return $this->_cookies;
    }

    /**
     * Sends the response content to the client
     */
    protected function sendContent()
    {
        if ($this->stream === null) {
            $this->swoole_http_response->end($this->content);
            // 这里看到还有写session的
            return;
        }

        $chunkSize = 8 * 1024 * 1024; // 8MB per chunk

        if (is_array($this->stream)) {
            list ($handle, $begin, $end) = $this->stream;
            fseek($handle, $begin);
            while (!feof($handle) && ($pos = ftell($handle)) <= $end) {
                if ($pos + $chunkSize > $end) {
                    $chunkSize = $end - $pos + 1;
                }
                $this->swoole_http_response->write(fread($handle, $chunkSize));
            }
            fclose($handle);
        } else {
            while (!feof($this->stream)) {
                $this->swoole_http_response->write(fread($this->stream, $chunkSize));
            }
            fclose($this->stream);
        }
        $this->swoole_http_response->end();

        // 这里看到还有写session的
    }

    public function sendFile($filePath, $attachmentName = null, $options = [])
    {
        if (!isset($options['mimeType'])) {
            $options['mimeType'] = FileHelper::getMimeTypeByExtension($filePath);
        }
        if ($attachmentName === null) {
            $attachmentName = basename($filePath);
        }
        $handle = fopen($filePath, 'rb');
        $this->sendStreamAsFile($handle, $attachmentName, $options);

        return $this;
    }

    public function sendContentAsFile($content, $attachmentName, $options = [])
    {
        $headers = $this->getHeaders();

        $contentLength = StringHelper::byteLength($content);
        $range = $this->getHttpRange($contentLength);

        if ($range === false) {
            $headers->set('Content-Range', "bytes */$contentLength");
            throw new RangeNotSatisfiableHttpException();
        }

        list($begin, $end) = $range;
        if ($begin != 0 || $end != $contentLength - 1) {
            $this->setStatusCode(206);
            $headers->set('Content-Range', "bytes $begin-$end/$contentLength");
            $this->content = StringHelper::byteSubstr($content, $begin, $end - $begin + 1);
        } else {
            $this->setStatusCode(200);
            $this->content = $content;
        }

        $mimeType = isset($options['mimeType']) ? $options['mimeType'] : 'application/octet-stream';
        $this->setDownloadHeaders($attachmentName, $mimeType, !empty($options['inline']), $end - $begin + 1);

        $this->format = self::FORMAT_RAW;

        return $this;
    }

    public function sendStreamAsFile($handle, $attachmentName, $options = [])
    {
        $headers = $this->getHeaders();
        if (isset($options['fileSize'])) {
            $fileSize = $options['fileSize'];
        } else {
            fseek($handle, 0, SEEK_END);
            $fileSize = ftell($handle);
        }

        $range = $this->getHttpRange($fileSize);
        if ($range === false) {
            $headers->set('Content-Range', "bytes */$fileSize");
            throw new RangeNotSatisfiableHttpException();
        }

        list($begin, $end) = $range;
        if ($begin != 0 || $end != $fileSize - 1) {
            $this->setStatusCode(206);
            $headers->set('Content-Range', "bytes $begin-$end/$fileSize");
        } else {
            $this->setStatusCode(200);
        }

        $mimeType = isset($options['mimeType']) ? $options['mimeType'] : 'application/octet-stream';
        $this->setDownloadHeaders($attachmentName, $mimeType, !empty($options['inline']), $end - $begin + 1);

        $this->format = self::FORMAT_RAW;
        $this->stream = [$handle, $begin, $end];

        return $this;
    }

    public function setDownloadHeaders($attachmentName, $mimeType = null, $inline = false, $contentLength = null)
    {
        $headers = $this->getHeaders();

        $disposition = $inline ? 'inline' : 'attachment';
        $headers->setDefault('Pragma', 'public')
            ->setDefault('Accept-Ranges', 'bytes')
            ->setDefault('Expires', '0')
            ->setDefault('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
            ->setDefault('Content-Disposition', $this->getDispositionHeaderValue($disposition, $attachmentName));

        if ($mimeType !== null) {
            $headers->setDefault('Content-Type', $mimeType);
        }

        if ($contentLength !== null) {
            $headers->setDefault('Content-Length', $contentLength);
        }

        return $this;
    }

    protected function getHttpRange($fileSize)
    {
        $rangeHeader = Yii::$app->getRequest()->getHeaders()->get('Range');
        if (!isset($rangeHeader) || $rangeHeader === '-') {
            return [0, $fileSize - 1];
        }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches)) {
            return false;
        }
        if ($matches[1] === '') {
            $start = $fileSize - $matches[2];
            $end = $fileSize - 1;
        } elseif ($matches[2] !== '') {
            $start = $matches[1];
            $end = $matches[2];
            if ($end >= $fileSize) {
                $end = $fileSize - 1;
            }
        } else {
            $start = $matches[1];
            $end = $fileSize - 1;
        }
        if ($start < 0 || $start > $end) {
            return false;
        } else {
            return [$start, $end];
        }
    }

    public function xSendFile($filePath, $attachmentName = null, $options = [])
    {
        if ($attachmentName === null) {
            $attachmentName = basename($filePath);
        }
        if (isset($options['mimeType'])) {
            $mimeType = $options['mimeType'];
        } elseif (($mimeType = FileHelper::getMimeTypeByExtension($filePath)) === null) {
            $mimeType = 'application/octet-stream';
        }
        if (isset($options['xHeader'])) {
            $xHeader = $options['xHeader'];
        } else {
            $xHeader = 'X-Sendfile';
        }

        $disposition = empty($options['inline']) ? 'attachment' : 'inline';
        $this->getHeaders()
            ->setDefault($xHeader, $filePath)
            ->setDefault('Content-Type', $mimeType)
            ->setDefault('Content-Disposition', $this->getDispositionHeaderValue($disposition, $attachmentName));

        $this->format = self::FORMAT_RAW;

        return $this;
    }

    protected function getDispositionHeaderValue($disposition, $attachmentName)
    {
        $fallbackName = str_replace('"', '\\"', str_replace(['%', '/', '\\'], '_', Inflector::transliterate($attachmentName, Inflector::TRANSLITERATE_LOOSE)));
        $utfName = rawurlencode(str_replace(['%', '/', '\\'], '', $attachmentName));

        $dispositionHeader = "{$disposition}; filename=\"{$fallbackName}\"";
        if ($utfName !== $fallbackName) {
            $dispositionHeader .= "; filename*=utf-8''{$utfName}";
        }
        return $dispositionHeader;
    }

    public function redirect($url, $statusCode = 302, $checkAjax = true)
    {
        if (is_array($url) && isset($url[0])) {
            // ensure the route is absolute
            $url[0] = '/' . ltrim($url[0], '/');
        }
        $url = Url::to($url);
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $url = Yii::$app->getRequest()->getHostInfo() . $url;
        }

        if ($checkAjax) {
            if (Yii::$app->getRequest()->getIsAjax()) {
                if (Yii::$app->getRequest()->getHeaders()->get('X-Ie-Redirect-Compatibility') !== null && $statusCode === 302) {
                    // Ajax 302 redirect in IE does not work. Change status code to 200. See https://github.com/yiisoft/yii2/issues/9670
                    $statusCode = 200;
                }
                if (Yii::$app->getRequest()->getIsPjax()) {
                    $this->getHeaders()->set('X-Pjax-Url', $url);
                } else {
                    $this->getHeaders()->set('X-Redirect', $url);
                }
            } else {
                $this->getHeaders()->set('Location', $url);
            }
        } else {
            $this->getHeaders()->set('Location', $url);
        }

        $this->setStatusCode($statusCode);

        return $this;
    }

    public function refresh($anchor = '')
    {
        return $this->redirect(Yii::$app->getRequest()->getUrl() . $anchor);
    }

}