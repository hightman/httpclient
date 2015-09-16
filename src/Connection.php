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
     * The maximum number of concurrent connections for the same host and port pair.
     */
    const MAX_BURST = 3;

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
     * Create connection, with built-in pool.
     * @param string $conn connection string, like `protocal://host:port`.
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
        if ($obj === null && count(self::$_objs[$conn]) < self::MAX_BURST) {
            $obj = new self($conn);
            self::$_objs[$conn][] = $obj;
            Client::debug('create conn \'', $conn, '\'');
        }
        if ($obj !== null) {
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
            $obj->arg = $arg;
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
        $n = fwrite($this->sock, $buf);
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
        $line = stream_get_line($this->sock, 2048, "\n");
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
        $buf = fread($this->sock, $size);
        if ($buf === '' || $buf === false) {
            $buf = $this->ioEmptyError() ? false : null;
        }
        $this->ioFlagReset();
        return $buf;
    }

    /**
     * Get the connection socket
     * @return resource the socket
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
            $this->flag |= self::FLAG_NEW2;
        }
        // async-connect
        $this->sock = stream_socket_client($this->conn, $errno, $error, 1, STREAM_CLIENT_ASYNC_CONNECT);
        if ($this->sock === false) {
            Client::debug($repeat ? 're' : '', 'open \'', $this->conn, '\' failed: ', $error);
            self::$_lastError = $error;
        } else {
            Client::debug($repeat ? 're' : '', 'open \'', $this->conn, '\' success: ', $this->sock);
            stream_set_blocking($this->sock, false);
            $this->flag |= self::FLAG_OPENED;
            $this->addSockRef();
        }
        $this->outBuf = null;
        $this->outLen = 0;
        return $this->sock;
    }

    protected function ioEmptyError()
    {
        if ($this->flag & self::FLAG_SELECT) {
            if (!($this->flag & self::FLAG_REUSED) || !$this->openSock(true)) {
                self::$_lastError = ($this->flag & self::FLAG_NEW) ? 'Fail to connect' : 'Reset by peer';
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
}
