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
 * Http request
 *
 * @author hightman
 * @since 1.0
 */
class Request
{
    use HeaderTrait;
    private $_url, $_urlParams, $_rawUrl, $_body;
    private $_method = 'GET';
    private $_maxRedirect = 5;
    private $_postFields = [];
    private $_postFiles = [];
    private static $_dns = [];
    private static $_mimes = [
        'gif' => 'image/gif', 'png' => 'image/png', 'bmp' => 'image/bmp',
        'jpeg' => 'image/jpeg', 'pjpg' => 'image/pjpg', 'jpg' => 'image/jpeg',
        'tif' => 'image/tiff', 'htm' => 'text/html', 'css' => 'text/css',
        'html' => 'text/html', 'txt' => 'text/plain', 'gz' => 'application/x-gzip',
        'tgz' => 'application/x-gzip', 'tar' => 'application/x-tar',
        'zip' => 'application/zip', 'hqx' => 'application/mac-binhex40',
        'doc' => 'application/msword', 'pdf' => 'application/pdf',
        'ps' => 'application/postcript', 'rtf' => 'application/rtf',
        'dvi' => 'application/x-dvi', 'latex' => 'application/x-latex',
        'swf' => 'application/x-shockwave-flash', 'tex' => 'application/x-tex',
        'mid' => 'audio/midi', 'au' => 'audio/basic', 'mp3' => 'audio/mpeg',
        'ram' => 'audio/x-pn-realaudio', 'ra' => 'audio/x-realaudio',
        'rm' => 'audio/x-pn-realaudio', 'wav' => 'audio/x-wav', 'wma' => 'audio/x-ms-media',
        'wmv' => 'video/x-ms-media', 'mpg' => 'video/mpeg', 'mpga' => 'video/mpeg',
        'wrl' => 'model/vrml', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
    ];

    /**
     * Constructor
     * @param string $url the request URL.
     * @param string $method the request method.
     */
    public function __construct($url = null, $method = null)
    {
        if ($url !== null) {
            $this->setUrl($url);
        }
        if ($method !== null) {
            $this->setMethod($method);
        }
    }

    /**
     * Convert to string
     * @return string url
     */
    public function __toString()
    {
        return $this->getUrl();
    }

    /**
     * Get max redirects
     * @return integer the max redirects.
     */
    public function getMaxRedirect()
    {
        return $this->_maxRedirect;
    }

    /**
     * Set max redirects
     * @param integer $num max redirects to be set.
     */
    public function setMaxRedirect($num)
    {
        $this->_maxRedirect = intval($num);
    }

    /**
     * @return string raw url
     */
    public function getRawUrl()
    {
        return $this->_rawUrl;
    }

    /**
     * Get request URL
     * @return string request url after handling
     */
    public function getUrl()
    {
        return $this->_url;
    }

    /**
     * Set request URL
     * Relative url will be converted to full url by adding host and protocol.
     * @param string $url raw url
     */
    public function setUrl($url)
    {
        $this->_rawUrl = $url;
        if (strncasecmp($url, 'http://', 7) && strncasecmp($url, 'https://', 8) && isset($_SERVER['HTTP_HOST'])) {
            if (substr($url, 0, 1) != '/') {
                $url = substr($_SERVER['SCRIPT_NAME'], 0, strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) . $url;
            }
            $url = 'http://' . $_SERVER['HTTP_HOST'] . $url;
        }
        $this->_url = str_replace('&amp;', '&', $url);
        $this->_urlParams = null;
    }

    /**
     * Get url parameters
     * @return array the parameters parsed from URL, or false on error
     */
    public function getUrlParams()
    {
        if ($this->_urlParams === null) {
            $pa = @parse_url($this->getUrl());
            $pa['scheme'] = isset($pa['scheme']) ? strtolower($pa['scheme']) : 'http';
            if ($pa['scheme'] !== 'http' && $pa['scheme'] !== 'https') {
                return false;
            }
            if (!isset($pa['host'])) {
                return false;
            }
            if (!isset($pa['path'])) {
                $pa['path'] = '/';
            }
            // basic auth
            if (isset($pa['user']) && isset($pa['pass'])) {
                $this->applyBasicAuth($pa['user'], $pa['pass']);
            }
            // convert host to IP address
            $port = isset($pa['port']) ? intval($pa['port']) : ($pa['scheme'] === 'https' ? 443 : 80);
            $pa['ip'] = $this->hasHeader('x-server-ip') ?
                $this->getHeader('x-server-ip') : self::getIp($pa['host']);
            $pa['conn'] = ($pa['scheme'] === 'https' ? 'ssl' : 'tcp') . '://' . $pa['ip'] . ':' . $port;
            // host header
            if (!$this->hasHeader('host')) {
                $this->setHeader('host', strtolower($pa['host']));
            } else {
                $pa['host'] = $this->getHeader('host');
            }
            $this->_urlParams = $pa;
        }
        return $this->_urlParams;
    }

    /**
     * Get url parameter by key
     * @param string $key parameter name
     * @return string the parameter value or null if non-exists.
     */
    public function getUrlParam($key)
    {
        $pa = $this->getUrlParams();
        return isset($pa[$key]) ? $pa[$key] : null;
    }

    /**
     * Get http request method
     * @return string the request method
     */
    public function getMethod()
    {
        return $this->_method;
    }

    /**
     * Set http request method
     * @param string $method request method
     */
    public function setMethod($method)
    {
        $this->_method = strtoupper($method);
    }

    /**
     * Get http request body
     * Appending post fields and files.
     * @return string request body
     */
    public function getBody()
    {
        $body = '';
        if ($this->_method === 'POST' || $this->_method === 'PUT') {
            if ($this->_body === null) {
                $this->_body = $this->getPostBody();
            }
            $this->setHeader('content-length', strlen($this->_body));
            $body = $this->_body . Client::CRLF;
        }
        return $body;
    }

    /**
     * Set http request body
     * @param string $body content string.
     */
    public function setBody($body)
    {
        $this->_body = $body;
        $this->setHeader('content-length', $body === null ? null : strlen($body));
    }

    /**
     * Set http request body as Json
     * @param mixed $data json data
     */
    public function setJsonBody($data)
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->setHeader('content-type', 'application/json');
        $this->setBody($body);
    }

    /**
     * Add field for the request use POST
     * @param string $key field name.
     * @param mixed $value field value, array supported.
     */
    public function addPostField($key, $value)
    {
        $this->setMethod('POST');
        $this->setBody(null);
        if (!is_array($value)) {
            $this->_postFields[$key] = strval($value);
        } else {
            $value = $this->formatArrayField($value);
            foreach ($value as $k => $v) {
                $k = $key . '[' . $k . ']';
                $this->_postFields[$k] = $v;
            }
        }
    }

    /**
     * Add file to be uploaded for request use POST
     * @param string $key field name.
     * @param string $file file path to be uploaded.
     * @param string $content file content, default to null and read from file.
     */
    public function addPostFile($key, $file, $content = null)
    {
        $this->setMethod('POST');
        $this->setBody(null);
        if ($content === null && is_file($file)) {
            $content = @file_get_contents($file);
        }
        $this->_postFiles[$key] = [basename($file), $content];
    }

    /**
     * Combine request body from post fields & files
     * @return string request body content
     */
    protected function getPostBody()
    {
        $data = '';
        if (count($this->_postFiles) > 0) {
            $boundary = md5($this->_rawUrl . microtime());
            foreach ($this->_postFields as $k => $v) {
                $data .= '--' . $boundary . Client::CRLF . 'Content-Disposition: form-data; name="' . $k . '"'
                    . Client::CRLF . Client::CRLF . $v . Client::CRLF;
            }
            foreach ($this->_postFiles as $k => $v) {
                $ext = strtolower(substr($v[0], strrpos($v[0], '.') + 1));
                $type = isset(self::$_mimes[$ext]) ? self::$_mimes[$ext] : 'application/octet-stream';
                $data .= '--' . $boundary . Client::CRLF . 'Content-Disposition: form-data; name="' . $k . '"; filename="' . $v[0] . '"'
                    . Client::CRLF . 'Content-Type: ' . $type . Client::CRLF . 'Content-Transfer-Encoding: binary'
                    . Client::CRLF . Client::CRLF . $v[1] . Client::CRLF;
            }
            $data .= '--' . $boundary . '--' . Client::CRLF;
            $this->setHeader('content-type', 'multipart/form-data; boundary=' . $boundary);
        } else {
            if (count($this->_postFields) > 0) {
                foreach ($this->_postFields as $k => $v) {
                    $data .= '&' . rawurlencode($k) . '=' . rawurlencode($v);
                }
                $data = substr($data, 1);
                $this->setHeader('content-type', 'application/x-www-form-urlencoded');
            }
        }
        return $data;
    }

    // get ip address
    protected static function getIp($host)
    {
        if (!isset(self::$_dns[$host])) {
            self::$_dns[$host] = gethostbyname($host);
        }
        return self::$_dns[$host];
    }

    // format array field (convert N-DIM(n>=2) array => 2-DIM array)
    private function formatArrayField($arr, $pk = null)
    {
        $ret = [];
        foreach ($arr as $k => $v) {
            if ($pk !== null) {
                $k = $pk . $k;
            }
            if (is_array($v)) {
                $ret = array_merge($ret, $this->formatArrayField($v, $k . ']['));
            } else {
                $ret[$k] = $v;
            }
        }
        return $ret;
    }

    // apply basic auth
    private function applyBasicAuth($user, $pass)
    {
        $this->setHeader('authorization', 'Basic ' . base64_encode($user . ':' . $pass));
    }
}
