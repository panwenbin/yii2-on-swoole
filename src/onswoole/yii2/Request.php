<?php
/**
 * @author Pan Wenbin <panwenbin@gmail.com>
 */

namespace onswoole\yii2;


use Yii;
use yii\base\InvalidConfigException;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;
use yii\web\NotFoundHttpException;
use yii\web\RequestParserInterface;

class Request extends \yii\web\Request
{
    /**
     * @var \Swoole\Http\Request
     */
    public $swoole_http_request;

    public $scriptFile;

    /**
     * @param \Swoole\Http\Request $swoole_http_request
     */
    public function setSwooleHttpRequest(\Swoole\Http\Request $swoole_http_request)
    {
        $this->swoole_http_request = $swoole_http_request;
    }

    /**
     * Resolves the current request into a route and the associated parameters.
     * @return array the first element is the route, and the second is the associated parameters.
     * @throws NotFoundHttpException if the request cannot be resolved.
     */
    public function resolve()
    {
        $result = Yii::$app->getUrlManager()->parseRequest($this);
        if ($result !== false) {
            list ($route, $params) = $result;
            $this->setQueryParams($params + $this->getQueryParams());
            return [$route, $this->getQueryParams()];
        } else {
            throw new NotFoundHttpException(Yii::t('yii', 'Page not found.'));
        }
    }

    /**
     * @var HeaderCollection Collection of request headers.
     */
    private $_headers;

    /**
     * @return \yii\web\HeaderCollection
     */
    public function getHeaders()
    {
        if ($this->_headers === null) {
            $this->_headers = new HeaderCollection;
            foreach ($this->swoole_http_request->header as $name => $value) {
                $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
                $this->_headers->add($name, $value);
            }
        }

        return $this->_headers;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->swoole_http_request->server['request_method'] ?? 'GET';
    }

    /**
     * @return bool
     */
    public function getIsGet()
    {
        return $this->getMethod() === 'GET';
    }

    /**
     * @return bool
     */
    public function getIsOptions()
    {
        return $this->getMethod() === 'OPTIONS';
    }

    /**
     * @return bool
     */
    public function getIsHead()
    {
        return $this->getMethod() === 'HEAD';
    }

    /**
     * @return bool
     */
    public function getIsPost()
    {
        return $this->getMethod() === 'POST';
    }

    /**
     * @return bool
     */
    public function getIsDelete()
    {
        return $this->getMethod() === 'DELETE';
    }

    /**
     * @return bool
     */
    public function getIsPut()
    {
        return $this->getMethod() === 'PUT';
    }

    /**
     * @return bool
     */
    public function getIsPatch()
    {
        return $this->getMethod() === 'PATCH';
    }

    /**
     * @return bool
     */
    public function getIsAjax()
    {
        return $this->getHeaders()->get('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * @return bool
     */
    public function getIsPjax()
    {
        return $this::getIsAjax() && $this->getHeaders()->has('X-Pjax');
    }

    /**
     * @return bool
     */
    public function getIsFlash()
    {
        $userAgent = $this->getHeaders()->get('User-Agent');
        return stripos($userAgent, 'Shockwave' !== false) || stripos($userAgent, 'Flash' !== false);
    }

    /**
     * @var
     */
    private $_rawBody;

    /**
     * @return string
     */
    public function getRawBody()
    {
        if($this->_rawBody === null && in_array($this->getMethod(), ['POST', 'PATCH']) ) {
            $this->_rawBody = $this->swoole_http_request->rawContent();
        }
        return $this->_rawBody;
    }

    /**
     * @param string $rawBody
     */
    public function setRawBody($rawBody)
    {
        $this->_rawBody = $rawBody;
    }

    /**
     * @var
     */
    private $_bodyParams;

    /**
     * @return array|null
     * @throws InvalidConfigException
     */
    public function getBodyParams()
    {
        if ($this->_bodyParams === null) {
            if (isset($this->swoole_http_request->post[$this->methodParam])) {
                $this->_bodyParams = $this->swoole_http_request->post;
                unset($this->_bodyParams[$this->methodParam]);
                return $this->_bodyParams;
            }

            $rawContentType = $this->getContentType();
            if (($pos = strpos($rawContentType, ';')) !== false) {
                // e.g. application/json; charset=UTF-8
                $contentType = substr($rawContentType, 0, $pos);
            } else {
                $contentType = $rawContentType;
            }

            if (isset($this->parsers[$contentType])) {
                $parser = Yii::createObject($this->parsers[$contentType]);
                if (!($parser instanceof RequestParserInterface)) {
                    throw new InvalidConfigException("The '$contentType' request parser is invalid. It must implement the yii\\web\\RequestParserInterface.");
                }
                $this->_bodyParams = $parser->parse($this->getRawBody(), $rawContentType);
            } elseif (isset($this->parsers['*'])) {
                $parser = Yii::createObject($this->parsers['*']);
                if (!($parser instanceof RequestParserInterface)) {
                    throw new InvalidConfigException("The fallback request parser is invalid. It must implement the yii\\web\\RequestParserInterface.");
                }
                $this->_bodyParams = $parser->parse($this->getRawBody(), $rawContentType);
            } elseif ($this->getMethod() === 'POST') {
                // swoole has already parsed the body so we have all params in swoole_http_request->post
                $this->_bodyParams = $this->swoole_http_request->post;
            } else {
                $this->_bodyParams = [];
                mb_parse_str($this->getRawBody(), $this->_bodyParams);
            }
        }
        return $this->_bodyParams;
    }

    /**
     * @param array $values
     */
    public function setBodyParams($values)
    {
        $this->_bodyParams = $values;
    }

    /**
     * @param string $name
     * @param null $defaultValue
     * @return mixed|null
     * @throws InvalidConfigException
     */
    public function getBodyParam($name, $defaultValue = null)
    {
        $params = $this->getBodyParams();

        return isset($params[$name]) ? $params[$name] : $defaultValue;
    }

    /**
     * @param null $name
     * @param null $defaultValue
     * @return array|mixed|null
     * @throws InvalidConfigException
     */
    public function post($name = null, $defaultValue = null)
    {
        if ($name === null) {
            return $this->getBodyParams();
        } else {
            return $this->getBodyParam($name, $defaultValue);
        }
    }

    /**
     * @var
     */
    private $_queryParams;

    /**
     * @return array
     */
    public function getQueryParams()
    {
        if ($this->_queryParams === null) {
            $this->_queryParams = $this->swoole_http_request->get ?? [];
        }
        return $this->_queryParams;
    }

    /**
     * @param array $values
     */
    public function setQueryParams($values)
    {
        $this->_queryParams = $values;
    }

    /**
     * @param null $name
     * @param null $defaultValue
     * @return array|mixed|null
     */
    public function get($name = null, $defaultValue = null)
    {
        if ($name === null) {
            return $this->getQueryParams();
        } else {
            return $this->getQueryParam($name, $defaultValue);
        }
    }

    /**
     * @param string $name
     * @param null $defaultValue
     * @return mixed|null
     */
    public function getQueryParam($name, $defaultValue = null)
    {
        $params = $this->getQueryParams();

        return isset($params[$name]) ? $params[$name] : $defaultValue;
    }

    /**
     * @var
     */
    private $_hostInfo;
    /**
     * @var
     */
    private $_hostName;

    /**
     * @return null|string
     */
    public function getHostInfo()
    {
        if ($this->_hostInfo === null) {
            $secure = $this->getIsSecureConnection();
            $schema = $secure ? 'https' : 'http';
            $host = $this->getHeaders()->get('Host');
            if ($host) {
                $this->_hostInfo = $schema . '://' . $host;
            }
        }
        return $this->_hostInfo;
    }

    /**
     * @param null|string $value
     */
    public function setHostInfo($value)
    {
        $this->_hostName = null;
        $this->_hostInfo = $value === null ? null : rtrim($value, '/');
    }

    /**
     * @return mixed|null|string
     */
    public function getHostName()
    {
        if ($this->_hostName === null) {
            $this->_hostName = parse_url($this->getHostInfo(), PHP_URL_HOST);
        }

        return $this->_hostName;
    }

    /**
     * @var
     */
    private $_scriptUrl;

    /**
     * @return string
     */
    public function getScriptUrl()
    {
        if ($this->_scriptUrl === null) {
            $this->_scriptUrl = '/';
        }
        return $this->_scriptUrl;
    }

    /**
     * @param string $value
     */
    public function setScriptUrl($value)
    {
        $this->_scriptUrl = $value === null ? null : '/' . trim($value, '/');
    }

    /**
     * @return bool|string
     */
    public function getScriptFile()
    {
        return $this->scriptFile;
    }

    /**
     * @param string $value
     */
    public function setScriptFile($value)
    {
        $this->scriptFile = $value;
    }

    /**
     * @var
     */
    private $_pathInfo;

    /**
     * @return bool|string
     */
    public function getPathInfo()
    {
        if ($this->_pathInfo === null) {
            $this->_pathInfo = $this->resolvePathInfo();
        }

        return $this->_pathInfo;
    }

    /**
     * @param string $value
     */
    public function setPathInfo($value)
    {
        $this->_pathInfo = $value === null ? null : ltrim($value, '/');
    }

    /**
     * @return bool|string
     */
    protected function resolvePathInfo()
    {
        $pathInfo = $this->swoole_http_request->server['path_info'] ?? '/';

        $pathInfo = urldecode($pathInfo);

        // try to encode in UTF8 if not so
        // http://w3.org/International/questions/qa-forms-utf-8.html
        if (!preg_match('%^(?:
            [\x09\x0A\x0D\x20-\x7E]              # ASCII
            | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
            | \xE0[\xA0-\xBF][\x80-\xBF]         # excluding overlongs
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
            | \xED[\x80-\x9F][\x80-\xBF]         # excluding surrogates
            | \xF0[\x90-\xBF][\x80-\xBF]{2}      # planes 1-3
            | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
            | \xF4[\x80-\x8F][\x80-\xBF]{2}      # plane 16
            )*$%xs', $pathInfo)
        ) {
            $pathInfo = utf8_encode($pathInfo);
        }

        if (substr($pathInfo, 0, 1) === '/') {
            $pathInfo = substr($pathInfo, 1);
        }
        return $pathInfo;
    }

    /**
     * @return string
     */
    public function getAbsoluteUrl()
    {
        return $this->getHostInfo() . $this->getUrl();
    }

    /**
     * @var
     */
    private $_url;

    /**
     * @return bool|null|string|string[]
     */
    public function getUrl()
    {
        if ($this->_url === null) {
            $this->_url = $this->resolveRequestUri();
        }

        return $this->_url;
    }

    /**
     * @param string $value
     */
    public function setUrl($value)
    {
        $this->_url = $value;
    }

    /**
     * @return bool|null|string|string[]
     */
    protected function resolveRequestUri()
    {
        $requestUri = $this->swoole_http_request->server['request_uri'] ?? '/';
        $requestUri .= isset($this->swoole_http_request->server['query_string']) ? '?' . $this->swoole_http_request->server['query_string'] : '';
        if ($requestUri !== '' && $requestUri[0] !== '/') {
            $requestUri = preg_replace('/^(http|https):\/\/[^\/]+/i', '', $requestUri);
        }
        return $requestUri;
    }

    /**
     * @return string
     */
    public function getQueryString()
    {
        return $this->swoole_http_request->server['query_string'] ?? '';
    }

    /**
     * @return bool
     */
    public function getIsSecureConnection()
    {
        return $this->getHeaders()->get('X-Forwarded-Proto') === 'https';
    }

    /**
     * @return array|string
     */
    public function getServerName()
    {
        return $this->getHeaders()->get('Host');
    }

    /**
     * @return int|null
     */
    public function getServerPort()
    {
        return $this->swoole_http_request->server['server_port'] ?? null;
    }

    /**
     * @return array|null|string
     */
    public function getReferrer()
    {
        return $this->getHeaders()->get('Referer');
    }

    /**
     * @return array|null|string
     */
    public function getUserAgent()
    {
        return $this->getHeaders()->get('User-Agent');
    }

    /**
     * @return null|string
     */
    public function getUserIP()
    {
        return $this->swoole_http_request->server['remote_addr'] ?? null;
    }

    /**
     * @return null|string
     */
    public function getUserHost()
    {
        return null;
    }

    /**
     * @return null|string
     */
    public function getAuthUser()
    {
        return $this->swoole_http_request->server['php_auth_user'] ?? null;
    }

    /**
     * @return null|string
     */
    public function getAuthPassword()
    {
        return $this->swoole_http_request->server['php_auth_pw'] ?? null;
    }

    /**
     * @var
     */
    private $_port;

    /**
     * @return int
     */
    public function getPort()
    {
        if ($this->_port === null) {
            $this->_port = $this->swoole_http_request->server['server_port'] ?? 80;
        }
        return $this->_port;
    }

    /**
     * @param int $value
     */
    public function setPort($value)
    {
        if ($value != $this->_port) {
            $this->_port = (int)$value;
            $this->_hostInfo = null;
        }
    }

    /**
     * @var
     */
    private $_securePort;

    /**
     * @return int|null
     */
    public function getSecurePort()
    {
        if ($this->_securePort === null) {
            $this->_port = $this->swoole_http_request->server['server_port'] ?? 443;
        }

        return $this->_securePort;
    }

    /**
     * @param int $value
     */
    public function setSecurePort($value)
    {
        if ($value != $this->_securePort) {
            $this->_securePort = (int)$value;
            $this->_hostInfo = null;
        }
    }

    /**
     * @var
     */
    private $_contentTypes;

    /**
     * @return array|null
     */
    public function getAcceptableContentTypes()
    {
        if ($this->_contentTypes === null) {
            $acceptHeader = $this->getHeaders()->get('Accept');
            if ($acceptHeader) {
                $this->_contentTypes = $this->parseAcceptHeader($acceptHeader);
            } else {
                $this->_contentTypes = [];
            }
        }

        return $this->_contentTypes;
    }

    /**
     * @param array $value
     */
    public function setAcceptableContentTypes($value)
    {
        $this->_contentTypes = $value;
    }

    /**
     * @return array|string
     */
    public function getContentType()
    {
        return $this->getHeaders()->get('Content-Type', null);
    }

    /**
     * @var
     */
    private $_languages;

    /**
     * @return array|null
     */
    public function getAcceptableLanguages()
    {
        if ($this->_languages === null) {
            $acceptLanguageHeader = $this->getHeaders()->get('Accept-Language');
            if ($acceptLanguageHeader) {
                $this->_languages = array_keys($this->parseAcceptHeader($acceptLanguageHeader));
            } else {
                $this->_languages = [];
            }
        }

        return $this->_languages;
    }

    /**
     * @param array $value
     */
    public function setAcceptableLanguages($value)
    {
        $this->_languages = $value;
    }

    /**
     * @return array|array[]|false|string[]
     */
    public function getETags()
    {
        $ifNoneMatchHeader = $this->getHeaders()->get('If-None-Match');
        if ($ifNoneMatchHeader) {
            return preg_split('/[\s,]+/', str_replace('-gzip', '', $ifNoneMatchHeader), -1, PREG_SPLIT_NO_EMPTY);
        } else {
            return [];
        }
    }

    /**
     * @var
     */
    private $_cookies;

    /**
     * @return CookieCollection
     * @throws InvalidConfigException
     */
    public function getCookies()
    {
        if ($this->_cookies === null) {
            $this->_cookies = new CookieCollection($this->loadCookies(), [
                'readOnly' => true,
            ]);
        }

        return $this->_cookies;
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    protected function loadCookies()
    {
        $cookies = [];
        if (empty($this->swoole_http_request->cookie)) {
            return $cookies;
        }
        if ($this->enableCookieValidation) {
            if ($this->cookieValidationKey == '') {
                throw new InvalidConfigException(get_class($this) . '::cookieValidationKey must be configured with a secret key.');
            }
            foreach ($this->swoole_http_request->cookie as $name => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $data = Yii::$app->getSecurity()->validateData($value, $this->cookieValidationKey);
                if ($data === false) {
                    continue;
                }
                $data = @unserialize($data);
                if (is_array($data) && isset($data[0], $data[1]) && $data[0] === $name) {
                    $cookies[$name] = new Cookie([
                        'name' => $name,
                        'value' => $data[1],
                        'expire' => null,
                    ]);
                }
            }
        } else {
            foreach ($this->swoole_http_request->cookie as $name => $value) {
                $cookies[$name] = new Cookie([
                    'name' => $name,
                    'value' => $value,
                    'expire' => null,
                ]);
            }
        }

        return $cookies;
    }
}