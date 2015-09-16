<?php
/**
 * A parallel HTTP client written in pure PHP
 *
 * @author hightman <hightman@twomice.net>
 * @link http://hightman.cn
 * @copyright Copyright (c) 2015 Twomice Studio.
 */

namespace hightman\http;

/**
 * Http client
 *
 * @author hightman
 * @since 1.0
 */
class Client
{
    use HeaderTrait;
	const PACKAGE = __CLASS__;
    const VERSION = '1.0.0-beta';
    const CRLF = "\r\n";

    private $_cookiePath, $_parser, $_timeout;
    private static $_debugOpen = false;
    private static $_processKey;

    /**
     * Open/close debug mode
     * @param string $msg
     */
    public static function debug($msg)
    {
        if ($msg === 'open' || $msg === 'close') {
            self::$_debugOpen = $msg === 'open';
        } elseif (self::$_debugOpen === true) {
            $key = self::$_processKey === null ? '' : '[' . self::$_processKey . '] ';
            echo '[DEBUG] ', date('H:i:s '), $key, implode('', func_get_args()), self::CRLF;
        }
    }

    /**
     * Decompress data
     * @param string $data compressed string
     * @return string result string
     */
    public static function gzdecode($data)
    {
        return gzinflate(substr($data, 10, -8));
    }

    /**
     * Constructor
     * @param callable $p response parse handler
     */
    public function __construct($p = null)
    {
        $this->applyDefaultHeader();
        $this->setParser($p);
    }

    /**
     * Destructor
     * Export and save all cookies.
     */
    public function __destruct()
    {
        if ($this->_cookiePath !== null) {
            $this->saveCookie($this->_cookiePath);
        }
    }

    /**
     * Set the max network read timeout
     * @param float $sec seconds, decimal support
     */
    public function setTimeout($sec)
    {
        $this->_timeout = floatval($sec);
    }

    /**
     * Set cookie storage path
     * If set, all cookies will be saved into this file, and send to request on need.
     * @param string $file file path to store cookies.
     */
    public function setCookiePath($file)
    {
        $this->_cookiePath = $file;
        $this->loadCookie($file);
    }

    /**
     * Set response parse handler
     * @param callable $p parse handler
     */
    public function setParser($p)
    {
        if ($p === null || $p instanceof ParseInterface || is_callable($p)) {
            $this->_parser = $p;
        }
    }

    /**
     * Run parse handler
     * @param Response $res response object
     * @param Request $req request object
     * @param mixed $key the key string of multi request
     */
    public function runParser($res, $req, $key = null)
    {
        if ($this->_parser !== null) {
            self::debug('run parser: ', $req->getRawUrl());
            if ($this->_parser instanceof ParseInterface) {
                $this->_parser->parse($res, $req, $key);
            } else {
                call_user_func($this->_parser, $res, $req, $key);
            }
        }
    }

    /**
     * Clear headers and apply defaults.
     */
    public function clearHeader()
    {
        parent::clearHeader();
        $this->applyDefaultHeader();
    }

    /**
     * Shortcut of HEAD request
     * @param string $url request URL string.
     * @param array $params query params appended to URL.
     * @return Response result response object.
     */
    public function head($url, $params = [])
    {
        if (is_array($url)) {
            return $this->mhead($url, $params);
        }
        return $this->exec($this->buildRequest('HEAD', $url, $params));
    }

    /**
     * Shortcut of HEAD multiple requests in parallel
     * @param array $urls request URL list.
     * @param array $params query params appended to each URL.
     * @return Response[] result response objects associated with key of URL.
     */
    public function mhead($urls, $params = [])
    {
        return $this->exec($this->buildRequests('HEAD', $urls, $params));
    }

    /**
     * Shortcut of GET request
     * @param string $url request URL string.
     * @param array $params extra query params, appended to URL.
     * @return Response result response object.
     */
    public function get($url, $params = [])
    {
        if (is_array($url)) {
            return $this->mget($url, $params);
        }
        return $this->exec($this->buildRequest('GET', $url, $params));
    }

    /**
     * Shortcut of GET multiple requests in parallel
     * @param array $urls request URL list.
     * @param array $params query params appended to each URL.
     * @return Response[] result response objects associated with key of URL.
     */
    public function mget($urls, $params = [])
    {
        return $this->exec($this->buildRequests('GET', $urls, $params));
    }

    /**
     * Shortcut of DELETE request
     * @param string $url request URL string.
     * @param array $params extra query params, appended to URL.
     * @return Response result response object.
     */
    public function delete($url, $params = [])
    {
        if (is_array($url)) {
            return $this->mdelete($url, $params);
        }
        return $this->exec($this->buildRequest('DELETE', $url, $params));
    }

    /**
     * Shortcut of DELETE multiple requests in parallel
     * @param array $urls request URL list.
     * @param array $params query params appended to each URL.
     * @return Response[] result response objects associated with key of URL.
     */
    public function mdelete($urls, $params = [])
    {
        return $this->exec($this->buildRequests('DELETE', $urls, $params));
    }

    /**
     * Shortcut of POST request
     * @param string|Request $url request URL string, or request object.
     * @param array $params post fields.
     * @return Response result response object.
     */
    public function post($url, $params = [])
    {
        $req = $url instanceof Request ? $url : $this->buildRequest('POST', $url);
        foreach ($params as $key => $value) {
            $req->addPostField($key, $value);
        }
        return $this->exec($req);
    }

    /**
     * Shortcut of PUT request
     * @param string|Request $url request URL string, or request object.
     * @param string $content content to be put.
     * @return Response result response object.
     */
    public function put($url, $content = '')
    {
        $req = $url instanceof Request ? $url : $this->buildRequest('PUT', $url);
        $req->setBody($content);
        return $this->exec($req);
    }

    /**
     * Shortcut of GET restful request as json format
     * @param string $url request URL string.
     * @param array $params extra query params, appended to URL.
     * @return array result json data, or false on failure.
     */
    public function getJson($url, $params = [])
    {
        $req = $this->buildRequest('GET', $url, $params);
        $req->setHeader('accept', 'application/json');
        $res = $this->exec($req);
        return $res === false ? false : $res->getJson();
    }

    /**
     * Shortcut of POST restful request as json format
     * @param string $url request URL string.
     * @param array $params request json data to be post.
     * @return array result json data, or false on failure.
     */
    public function postJson($url, $params = [])
    {
        $req = $this->buildRequest('POST', $url);
        $req->setHeader('accept', 'application/json');
        $req->setJsonBody($params);
        $res = $this->exec($req);
        return $res === false ? false : $res->getJson();
    }

    /**
     * Shortcut of PUT restful request as json format
     * @param string $url request URL string.
     * @param array $params request json data to be put.
     * @return array result json data, or false on failure.
     */
    public function putJson($url, $params = [])
    {
        $req = $this->buildRequest('PUT', $url);
        $req->setHeader('accept', 'application/json');
        $req->setJsonBody($params);
        $res = $this->exec($req);
        return $res === false ? false : $res->getJson();
    }

    /**
     * Execute http requests
     * @param Request|Request[] $req the request object, or array of multiple requests
     * @return Response|Response[] result response object, or response array for multiple requests
     */
    public function exec($req)
    {
        // build recs
        $recs = [];
        if ($req instanceof Request) {
            $recs[] = new Processor($this, $req);
        } elseif (is_array($req)) {
            foreach ($req as $key => $value) {
                if ($value instanceof Request) {
                    $recs[$key] = new Processor($this, $value, $key);
                }
            }
        }
        if (count($recs) === 0) {
            return false;
        }
        // loop to process
        while (true) {
            // build select fds
            $rfds = $wfds = $xrec = [];
            $xfds = null;
            foreach ($recs as $rec) {
                /* @var $rec Processor */
                self::$_processKey = $rec->key;
                if ($rec->finished || !($conn = $rec->getConn())) {
                    continue;
                }
                if ($this->_timeout !== null) {
                    $xrec[] = $rec;
                }
                $rfds[] = $conn->getSock();
                if ($conn->hasDataToWrite()) {
                    $wfds[] = $conn->getSock();
                }
            }
            self::$_processKey = null;
            if (count($rfds) === 0 && count($wfds) === 0) {
                // all tasks finished
                break;
            }
            // select sockets
            self::debug('stream_select(rfds[', count($rfds), '], wfds[', count($wfds), ']) ...');
            if ($this->_timeout === null) {
                $num = stream_select($rfds, $wfds, $xfds, null);
            } else {
                $sec = intval($this->_timeout);
                $usec = intval(($this->_timeout - $sec) * 1000000);
                $num = stream_select($rfds, $wfds, $xfds, $sec, $usec);
            }
            self::debug('select result: ', $num === false ? 'false' : $num);
            if ($num === false) {
                trigger_error('stream_select() error', E_USER_WARNING);
                break;
            } elseif ($num > 0) {
                // wfds
                foreach ($wfds as $sock) {
                    if (!($conn = Connection::findBySock($sock))) {
                        continue;
                    }
                    $rec = $conn->getExArg();
                    /* @var $rec Processor */
                    self::$_processKey = $rec->key;
                    $rec->send();
                }
                // rfds
                foreach ($rfds as $sock) {
                    if (!($conn = Connection::findBySock($sock))) {
                        continue;
                    }
                    $rec = $conn->getExArg();
                    /* @var $rec Processor */
                    self::$_processKey = $rec->key;
                    $rec->recv();
                }
            } else {
                // force to close request
                foreach ($xrec as $rec) {
                    self::$_processKey = $rec->key;
                    $rec->finish('TIMEOUT');
                }
            }
        }
        // return value
        if (!is_array($req)) {
            $ret = $recs[0]->res;
        } else {
            $ret = [];
            foreach ($recs as $key => $rec) {
                $ret[$key] = $rec->res;
            }
        }
        return $ret;
    }

    /**
     * Build a http request
     * @param string $method
     * @param string $url
     * @param array $params
     * @return Request
     */
    protected function buildRequest($method, $url, $params = [])
    {
        if (count($params) > 0) {
            $url .= strpos($url, '?') === false ? '?' : '&';
            $url .= http_build_query($params);
        }
        return new Request($url, $method);
    }

    /**
     * Build multiple http requests
     * @param string $method
     * @param array $urls
     * @param array $params
     * @return Request[]
     */
    protected function buildRequests($method, $urls, $params = [])
    {
        $reqs = [];
        foreach ($urls as $key => $url) {
            $reqs[$key] = $this->buildRequest($method, $url, $params);
        }
        return $reqs;
    }

    /**
     * @return string default user-agent
     */
    protected function defaultAgent()
    {
        $agent = 'Mozilla/5.0 (Compatible; ' . self::PACKAGE . '/' . self::VERSION . ') ';
        $agent .= 'php-' . php_sapi_name() . '/' . phpversion() . ' ';
        $agent .= php_uname('s') . '/' . php_uname('r');
        return $agent;
    }

    /**
     * Default HTTP headers
     */
    protected function applyDefaultHeader()
    {
        $this->setHeader([
            'accept' => '*/*',
            'accept-language' => 'zh-cn,zh',
            'connection' => 'Keep-Alive',
            'user-agent' => $this->defaultAgent(),
        ]);
    }
}
