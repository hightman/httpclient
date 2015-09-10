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
 * Http base operations
 * Handle cookie and other headers.
 *
 * @author hightman
 * @since 1.0
 */
trait HeaderTrait
{
    protected $_headers = [];
    protected $_cookies = [];

    /**
     * Set http header or headers
     * @param mixed $key string key or key-value pairs to set multiple headers.
     * @param string $value the header value when key is string, set null to remove header.
     */
    public function setHeader($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->setHeader($k, $v);
            }
        } else {
            $key = strtolower($key);
            if ($value === null) {
                unset($this->_headers[$key]);
            } else {
                $this->_headers[$key] = $value;
            }
        }
    }

    /**
     * Add http header or headers
     * @param mixed $key string key or key-value pairs to be added.
     * @param string $value the header value when key is string.
     */
    public function addHeader($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->addHeader($k, $v);
            }
        } else {
            if ($value !== null) {
                $key = strtolower($key);
                if (!isset($this->_headers[$key])) {
                    $this->_headers[$key] = $value;
                } else {
                    if (is_array($this->_headers[$key])) {
                        $this->_headers[$key][] = $value;
                    } else {
                        $this->_headers[$key] = [$this->_headers[$key], $value];
                    }
                }
            }
        }
    }

    /**
     * Clean http header
     */
    public function clearHeader()
    {
        $this->_headers = [];
    }

    /**
     * Get a http header or all http headers
     * @param mixed $key the header key to be got, or null to get all headers
     * @return array|string the header value, or headers array when key is null.
     */
    public function getHeader($key = null)
    {
        if ($key === null) {
            return $this->_headers;
        }
        $key = strtolower($key);
        return isset($this->_headers[$key]) ? $this->_headers[$key] : null;
    }

    /**
     * Check HTTP header is set or not
     * @param string $key the header key to be check, not case sensitive
     * @return boolean if there is http header with the name.
     */
    public function hasHeader($key)
    {
        return isset($this->_headers[strtolower($key)]);
    }

    /**
     * Set a raw cookie
     * @param string $key cookie name
     * @param string $value cookie value
     * @param integer $expires cookie will be expired after this timestamp.
     * @param string $domain cookie domain
     * @param string $path cookie path
     */
    public function setRawCookie($key, $value, $expires = null, $domain = '-', $path = '/')
    {
        $domain = strtolower($domain);
        if (substr($domain, 0, 1) === '.') {
            $domain = substr($domain, 1);
        }
        if (!isset($this->_cookies[$domain])) {
            $this->_cookies[$domain] = [];
        }
        if (!isset($this->_cookies[$domain][$path])) {
            $this->_cookies[$domain][$path] = [];
        }
        $list = &$this->_cookies[$domain][$path];
        if ($value === null || $value === '' || ($expires !== null && $expires < time())) {
            unset($list[$key]);
        } else {
            $list[$key] = ['value' => $value, 'expires' => $expires];
        }
    }

    /**
     * Set a normal cookie
     * @param string $key cookie name
     * @param string $value cookie value
     */
    public function setCookie($key, $value)
    {
        $this->setRawCookie($key, rawurlencode($value));
    }

    /**
     * Clean all cookies
     * @param string $domain use null to clean all cookies, '-' to clean current cookies.
     * @param string $path
     */
    public function clearCookie($domain = '-', $path = null)
    {
        if ($domain === null) {
            $this->_cookies = [];
        } else {
            $domain = strtolower($domain);
            if ($path === null) {
                unset($this->_cookies[$domain]);
            } else {
                if (isset($this->_cookies[$domain])) {
                    unset($this->_cookies[$domain][$path]);
                }
            }
        }
    }

    /**
     * Get cookie value
     * @param string $key passing null to get all cookies
     * @param string $domain passing '-' to fetch from current session,
     * @return array|null|string
     */
    public function getCookie($key, $domain = '-')
    {
        $domain = strtolower($domain);
        if ($key === null) {
            $cookies = [];
        }
        while (true) {
            if (isset($this->_cookies[$domain])) {
                foreach ($this->_cookies[$domain] as $path => $list) {
                    if ($key === null) {
                        $cookies = array_merge($list, $cookies);
                    } else {
                        if (isset($list[$key])) {
                            return rawurldecode($list[$key]['value']);
                        }
                    }
                }
            }
            if (($pos = strpos($domain, '.', 1)) === false) {
                break;
            }
            $domain = substr($domain, $pos);
        }
        return $key === null ? $cookies : null;
    }

    /**
     * Apply cookies for request
     * @param Request $req
     */
    public function applyCookie($req)
    {
        // fetch cookies
        $host = $req->getHeader('host');
        $path = $req->getUrlParam('path');
        $cookies = $this->fetchCookieToSend($host, $path);
        if ($this !== $req) {
            $cookies = array_merge($cookies, $req->fetchCookieToSend($host, $path));
        }
        // add to header
        $req->setHeader('cookie', null);
        foreach (array_chunk(array_values($cookies), 3) as $chunk) {
            $req->addHeader('cookie', implode('; ', $chunk));
        }
    }

    /**
     * Fetch cookies to be sent
     * @param string $host
     * @param string $path
     * @return array
     */
    public function fetchCookieToSend($host, $path)
    {
        $now = time();
        $host = strtolower($host);
        $cookies = [];
        $domains = ['-', $host];
        while (strlen($host) > 1 && ($pos = strpos($host, '.', 1)) !== false) {
            $host = substr($host, $pos + 1);
            $domains[] = $host;
        }
        foreach ($domains as $domain) {
            if (!isset($this->_cookies[$domain])) {
                continue;
            }
            foreach ($this->_cookies[$domain] as $_path => $list) {
                if (!strncmp($_path, $path, strlen($_path))
                    && (substr($_path, -1, 1) === '/' || substr($path, strlen($_path), 1) === '/')
                ) {
                    foreach ($list as $k => $v) {
                        if (!isset($cookies[$k]) && ($v['expires'] === null || $v['expires'] > $now)) {
                            $cookies[$k] = $k . '=' . $v['value'];
                        }
                    }
                }
            }
        }
        return $cookies;
    }

    protected function fetchCookieToSave()
    {
        $now = time();
        $cookies = [];
        foreach ($this->_cookies as $domain => $_list1) {
            $list1 = [];
            foreach ($_list1 as $path => $_list2) {
                $list2 = [];
                foreach ($_list2 as $k => $v) {
                    if ($v['expires'] === null || $v['expires'] < $now) {
                        continue;
                    }
                    $list2[$k] = $v;
                }
                if (count($list2) > 0) {
                    $list1[$path] = $list2;
                }
            }
            if (count($list1) > 0) {
                $cookies[$domain] = $list1;
            }
        }
        return $cookies;
    }

    protected function loadCookie($file)
    {
        if (file_exists($file)) {
            $this->_cookies = unserialize(file_get_contents($file));
        }
    }

    protected function saveCookie($file)
    {
        file_put_contents($file, serialize($this->fetchCookieToSave()));
    }
}
