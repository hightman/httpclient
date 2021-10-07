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
 * Connection manager
 *
 * @author hightman
 * @since 1.0
 */
class Connection
{
    /**
     * The connection socket flags
     */
    const FLAG_NEW = 0x01;
    const FLAG_NEW2 = 0x02;
    const FLAG_BUSY = 0x04;
    const FLAG_OPENED = 0x08;
    const FLAG_REUSED = 0x10;
    const FLAG_SELECT = 0x20;

    protected $outBuf, $outLen;
    protected $arg, $sock, $conn, $flag = 0;
    private static $_objs = [];
    private static $_refs = [];
    private static $_lastError;

    /**
     * @var int proxy state
     */
    public $proxyState = 0;

    /**
     * @var array proxy setting
     */
    private static $_proxy = null;

    /**
     * Set socks5 proxy server
     * @param string $host proxy server address, passed null to disable
     * @param int $port proxy server port, default to 1080
     * @param string $user authentication username
     * @param string $pass authentication password
     * @deprecated use `useProxy` instead
     */
    public static function useSocks5($host, $port = 1080, $user = null, $pass = null)
    {
        $url = 'socks5://';
        if ($user !== null && $pass !== null) {
            $url .= $user . ':' . $pass . '@';
        }
        $url .= $host . ':' . $port;
        self::useProxy($url);
    }

    /**
     * Proxy setting
     *   - socks5 with authentication: socks5://user:pass@127.0.0.1:1080
     *   - socks4: socks4://127.0.0.1:1080
     *   - http with authentication: http://user:pass@127.0.0.1:8080
     *   - http without authentication: 127.0.0.1:1080
     * @param string $url proxy setting URL
     */
    public static function useProxy($url)
    {
        self::$_proxy = null;
        if (!empty($url)) {
            $pa = parse_url($url);
            if (!isset($pa['scheme'])) {
                $pa['scheme'] = 'http';
            } else {
                $pa['scheme'] = strtolower($pa['scheme']);
            }
            if (!isset($pa['port'])) {
                $pa['port'] = substr($pa['scheme'], 0, 5) === 'socks' ? 1080 : 80;
            }
            if (isset($pa['user']) && !isset($pa['pass'])) {
                $pa['pass'] = '';
            }
            if ($pa['scheme'] === 'tcp' || $pa['scheme'] === 'https') {
                $pa['scheme'] = 'http';
            }
            if ($pa['scheme'] === 'socks') {
                $pa['scheme'] = isset($pa['user']) ? 'socks5' : 'socks4';
            }
            if (in_array($pa['scheme'], ['http', 'socks4', 'socks5'])) {
                self::$_proxy = $pa;
                Client::debug('use proxy: ', $url);
            } else {
                Client::debug('invalid proxy url: ', $url);
            }
        }
    }

    /**
     * Create connection, with built-in pool.
     * @param string $conn connection string, like `protocol://host:port`.
     * @param mixed $arg external argument, fetched by `[[getExArg()]]`
     * @return static the connection object, null if it reaches the upper limit of concurrent, or false on failure.
     */
    public static function connect($conn, $arg = null)
    {
        $obj = null;
        if (!isset(self::$_objs[$conn])) {
            self::$_objs[$conn] = [];
        }
        foreach (self::$_objs[$conn] as $tmp) {
            if (!($tmp->flag & self::FLAG_BUSY)) {
                Client::debug('reuse conn \'', $tmp->conn, '\': ', $tmp->sock);
                $obj = $tmp;
                break;
            }
        }
        if ($obj === null && count(self::$_objs[$conn]) < Client::$maxBurst) {
            $obj = new self($conn);
            self::$_objs[$conn][] = $obj;
            Client::debug('create conn \'', $conn, '\'');
        }
        if ($obj !== null) {
            $obj->arg = $arg;
            if ($obj->flag & self::FLAG_OPENED) {
                $obj->flag |= self::FLAG_REUSED;
            } else {
                if (!$obj->openSock()) {
                    return false;
                }
            }
            $obj->flag |= self::FLAG_BUSY;
            $obj->outBuf = null;
            $obj->outLen = 0;
        }
        return $obj;
    }

    /**
     * Find connection object by socket, used after stream_select()
     * @param resource $sock
     * @return Connection the connection object or null if not found.
     */
    public static function findBySock($sock)
    {
        $sock = strval($sock);
        return isset(self::$_refs[$sock]) ? self::$_refs[$sock] : null;
    }

    /**
     * Get last error
     * @return string the last error message.
     */
    public static function getLastError()
    {
        return self::$_lastError;
    }

    /**
     * Close the connection
     * @param boolean $realClose whether to shutdown the connection, default is added to the pool for next request.
     */
    public function close($realClose = false)
    {
        $this->arg = null;
        $this->flag &= ~self::FLAG_BUSY;
        if ($realClose === true) {
            Client::debug('close conn \'', $this->conn, '\': ', $this->sock);
            $this->flag &= ~self::FLAG_OPENED;
            @fclose($this->sock);
            $this->delSockRef();
            $this->sock = false;
        } else {
            Client::debug('free conn \'', $this->conn, '\': ', $this->sock);
        }
    }

    /**
     * Append writing cache
     * @param $buf string data content.
     */
    public function addWriteData($buf)
    {
        if ($this->outBuf === null) {
            $this->outBuf = $buf;
        } else {
            $this->outBuf .= $buf;
        }
    }

    /**
     * @return boolean if there is data to be written.
     */
    public function hasDataToWrite()
    {
        if ($this->proxyState > 0) {
            return $this->proxyState & 1 ? true : false;
        }
        return ($this->outBuf !== null && strlen($this->outBuf) > $this->outLen);
    }

    /**
     * Write data to socket
     * @param string $buf the string to be written, passing null to flush cache.
     * @return mixed the number of bytes were written, 0 if the buffer is full, or false on error.
     */
    public function write($buf = null)
    {
        if ($buf === null) {
            if ($this->proxyState > 0) {
                return $this->proxyWrite();
            }
            $len = 0;
            if ($this->hasDataToWrite()) {
                $buf = $this->outLen > 0 ? substr($this->outBuf, $this->outLen) : $this->outBuf;
                $len = $this->write($buf);
                if ($len !== false) {
                    $this->outLen += $len;
                }
            }
            return $len;
        }
        $n = @fwrite($this->sock, $buf);
        Client::debug('write data to socket: ', strlen($buf), ' = ', $n === false ? 'false' : $n);
        if ($n === 0 && $this->ioEmptyError()) {
            $n = false;
        }
        $this->ioFlagReset();
        return $n;
    }

    /**
     * Read one line (not contains \r\n at the end)
     * @return mixed line string, null when has not data, or false on error.
     */
    public function getLine()
    {
        $line = @stream_get_line($this->sock, 2048, "\n");
        if ($line === '' || $line === false) {
            $line = $this->ioEmptyError() ? false : null;
        } else {
            $line = rtrim($line, "\r");
        }
        $this->ioFlagReset();
        return $line;
    }

    /**
     * Read data from socket
     * @param int $size the max number of bytes to be read.
     * @return mixed the read string, null when has not data, or false on error.
     */
    public function read($size = 8192)
    {
        $buf = @fread($this->sock, $size);
        if ($buf === '' || $buf === false) {
            $buf = $this->ioEmptyError() ? false : null;
        }
        $this->ioFlagReset();
        return $buf;
    }

    /**
     * Read data for proxy communication
     * @return bool
     */
    public function proxyRead()
    {
        $proxyState = $this->proxyState;
        Client::debug(self::$_proxy['scheme'], ' proxy readState: ', $proxyState);
        if (self::$_proxy['scheme'] === 'http') {
            while (($line = $this->getLine()) !== null) {
                if ($line === false) {
                    return false;
                }
                if ($line === '') {
                    $this->proxyState = 0;
                    break;
                }
                $this->proxyState++;
                Client::debug('read http proxy line: ', $line);
                if (!strncmp('HTTP/', $line, 5)) {
                    $line = trim(substr($line, strpos($line, ' ')));
                    if (intval($line) !== 200) {
                        self::$_lastError = 'Proxy response error: ' . $line;
                        return false;
                    }
                }
            }
        } elseif (self::$_proxy['scheme'] === 'socks4') {
            if ($proxyState === 2) {
                $buf = $this->read(8);
                if (substr($buf, 0, 2) === "\x00\x5A") {
                    $this->proxyState = 0;
                }
            }
        } elseif (self::$_proxy['scheme'] === 'socks5') {
            if ($proxyState === 2) {
                $buf = $this->read(2);
                if ($buf === "\x05\x00") {
                    $this->proxyState = 5;
                } elseif ($buf === "\x05\x02") {
                    $this->proxyState = 3;
                }
            } elseif ($proxyState === 4) {
                $buf = $this->read(2);
                if ($buf === "\x01\x00") {
                    $this->proxyState = 5;
                }
            } elseif ($proxyState === 6) {
                $buf = $this->read(10);
                if (substr($buf, 0, 4) === "\x05\x00\x00\x01") {
                    $this->proxyState = 0;
                }
            }
        }
        if ($proxyState === $this->proxyState) {
            self::$_lastError = 'Proxy response error: state=' . $proxyState;
            if (isset($buf)) {
                $unpack = unpack('H*', $buf);
                self::$_lastError .= ', buf=' . $unpack[1];
            }
            return false;
        } else {
            if ($this->proxyState === 0 && !strncmp($this->conn, 'ssl:', 4)) {
                Client::debug('enable crypto via proxy tunnel');
                if ($this->enableCrypto() !== true) {
                    self::$_lastError = 'Enable crypto error: ' . self::lastPhpError();
                    return false;
                }
            }
            return true;
        }
    }

    /**
     * Write data for proxy communication
     * @return mixed
     */
    public function proxyWrite()
    {
        Client::debug(self::$_proxy['scheme'], ' proxy writeState: ', $this->proxyState);
        if (self::$_proxy['scheme'] === 'http') {
            if ($this->proxyState === 1) {
                $pa = parse_url($this->conn);
                $buf = 'CONNECT ' . $pa['host'] . ':' . (isset($pa['port']) ? $pa['port'] : 80) . ' HTTP/1.1' . Client::CRLF;
                $buf .= 'Proxy-Connection: Keep-Alive' . Client::CRLF . 'Content-Length: 0' . Client::CRLF;
                if (isset(self::$_proxy['user'])) {
                    $buf .= 'Proxy-Authorization: Basic ' . base64_encode(self::$_proxy['user'] . ':' . self::$_proxy['pass']) . Client::CRLF;
                }
                $buf .= Client::CRLF;
                $this->proxyState++;
                return $this->write($buf);
            } else {
                // wait other response lines
                $this->proxyState++;
            }
        } elseif (self::$_proxy['scheme'] === 'socks4') {
            if ($this->proxyState === 1) {
                $pa = parse_url($this->conn);
                $buf = "\x04\x01" . pack('nN', isset($pa['port']) ? $pa['port'] : 80, ip2long($pa['host'])) . "\x00";
                $this->proxyState++;
                return $this->write($buf);
            }
        } elseif (self::$_proxy['scheme'] === 'socks5') {
            if ($this->proxyState === 1) {
                $buf = isset(self::$_proxy['user']) ? "\x05\x01\x02" : "\x05\x01\x00";
                $this->proxyState++;
                return $this->write($buf);
            } elseif ($this->proxyState === 3) {
                $buf = chr(0x01) . chr(strlen(self::$_proxy['user'])) . self::$_proxy['user']
                    . chr(strlen(self::$_proxy['pass'])) . self::$_proxy['pass'];
                $this->proxyState++;
                return $this->write($buf);
            } elseif ($this->proxyState === 5) {
                $pa = parse_url($this->conn);
                $buf = "\x05\x01\x00\x01" . pack('Nn', ip2long($pa['host']), isset($pa['port']) ? $pa['port'] : 80);
                $this->proxyState++;
                return $this->write($buf);
            }
        }
        return false;
    }

    /**
     * Get the connection socket
     * @return resource|false the socket
     */
    public function getSock()
    {
        $this->flag |= self::FLAG_SELECT;
        return $this->sock;
    }

    /**
     * @return mixed the external argument
     */
    public function getExArg()
    {
        return $this->arg;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->close(true);
    }

    /**
     * @param boolean $repeat whether it is repeat connection
     * @return resource the connection socket
     */
    protected function openSock($repeat = false)
    {
        $this->delSockRef();
        $this->flag |= self::FLAG_NEW;
        if ($repeat === true) {
            @fclose($this->sock);
            $this->flag |= self::FLAG_NEW2;
        }
        // context options
        $useProxy = self::$_proxy !== null;
        $ctx = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
        if ($this->arg instanceof Processor) {
            $req = $this->arg->req;
            if ($req->disableProxy === true) {
                $useProxy = false;
            }
            if (!strncmp($this->conn, 'ssl:', 4)) {
                $ctx['ssl']['peer_name'] = $req->getUrlParam('host');
            }
            if (is_array($req->contextOptions)) {
                foreach ($req->contextOptions as $key => $value) {
                    if (isset($ctx[$key])) {
                        $ctx[$key] = array_merge($ctx[$key], $value);
                    } else {
                        $ctx[$key] = $value;
                    }
                }
            }
        }
        $conn = $useProxy ? 'tcp://' . self::$_proxy['host'] . ':' . self::$_proxy['port'] : $this->conn;
        $this->sock = @stream_socket_client($conn, $errno, $error, 10, STREAM_CLIENT_ASYNC_CONNECT, stream_context_create($ctx));
        if ($this->sock === false) {
            if (empty($error)) {
                $error = self::lastPhpError();
            }
            Client::debug($repeat ? 're' : '', 'open \'', $conn, '\' failed: ', $error);
            self::$_lastError = $error;
        } else {
            Client::debug($repeat ? 're' : '', 'open \'', $conn, '\' success: ', $this->sock);
            stream_set_blocking($this->sock, false);
            $this->flag |= self::FLAG_OPENED;
            $this->addSockRef();
            if ($useProxy === true) {
                $this->proxyState = 1;
            }
        }
        return $this->sock;
    }

    protected function ioEmptyError()
    {
        if ($this->flag & self::FLAG_SELECT) {
            if (substr($this->conn, 0, 4) === 'ssl:') {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['eof'] !== true && $meta['unread_bytes'] === 0) {
                    return false;
                }
            }
            if (!($this->flag & self::FLAG_REUSED) || !$this->openSock(true)) {
                self::$_lastError = ($this->flag & self::FLAG_NEW) ? 'Unable to connect' : 'Stream read error';
                self::$_lastError .= ': ' . self::lastPhpError();
                return true;
            }
        }
        return false;
    }

    protected function ioFlagReset()
    {
        $this->flag &= ~(self::FLAG_NEW | self::FLAG_REUSED | self::FLAG_SELECT);
        if ($this->flag & self::FLAG_NEW2) {
            $this->flag |= self::FLAG_NEW;
            $this->flag ^= self::FLAG_NEW2;
        }
    }

    protected function addSockRef()
    {
        if ($this->sock !== false) {
            $sock = strval($this->sock);
            self::$_refs[$sock] = $this;
        }
    }

    protected function delSockRef()
    {
        if ($this->sock !== false) {
            $sock = strval($this->sock);
            unset(self::$_refs[$sock]);
        }
    }

    protected function __construct($conn)
    {
        $this->conn = $conn;
        $this->sock = false;
    }

    private function enableCrypto($enable = true)
    {
        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        stream_set_blocking($this->sock, true);
        $res = @stream_socket_enable_crypto($this->sock, $enable, $method);
        stream_set_blocking($this->sock, false);
        return $res === true;
    }

    private static function lastPhpError()
    {
        $error = error_get_last();
        return ($error !== null && isset($error['message'])) ? $error['message'] : null;
    }
}
