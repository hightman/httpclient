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
 * Http processor
 * Handle requests
 * @author hightman
 * @since 1.0
 */
class Processor
{
    /**
     * @var string request key
     */
    public $key;

    /**
     * @var Client client object
     */
    public $cli;

    /**
     * @var Request request object
     */
    public $req;

    /**
     * @var Response response object
     */
    public $res;

    /**
     * @var Connection
     */
    public $conn = null;

    /**
     * @var boolean whether the process is completed
     */
    public $finished;

    protected $headerOk, $timeBegin, $chunkLeft;

    /**
     * Constructor
     * @param Client $cli
     * @param Request $req
     * @param string|integer $key
     */
    public function __construct($cli, $req, $key = null)
    {
        $this->cli = $cli;
        $this->req = $req;
        $this->key = $key;
        $this->res = new Response($req->getRawUrl());
        $this->finished = $this->headerOk = false;
        $this->timeBegin = microtime(true);
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        if ($this->conn) {
            $this->conn->close();
        }
        $this->req = $this->cli = $this->res = $this->conn = null;
    }

    /**
     * Get connection
     * @return Connection the connection object, returns null if the connection fails or need to queue.
     */
    public function getConn()
    {
        if ($this->conn === null) {
            $this->conn = Connection::connect($this->req->getUrlParam('conn'), $this);
            if ($this->conn === false) {
                $this->res->error = Connection::getLastError();
                $this->finish();
            } else {
                if ($this->conn !== null) {
                    $this->conn->addWriteData($this->getRequestBuf());
                }
            }
        }
        return $this->conn;
    }

    public function send()
    {
        if ($this->conn->write() === false) {
            $this->finish('BROKEN');
        }
    }

    public function recv()
    {
        return $this->headerOk ? $this->readBody() : $this->readHeader();
    }

    /**
     * Finish the processor
     * @param string $type finish type, supports: NORMAL, BROKEN, TIMEOUT
     */
    public function finish($type = 'NORMAL')
    {
        $this->finished = true;
        if ($type === 'BROKEN') {
            $this->res->error = Connection::getLastError();
        } else {
            if ($type !== 'NORMAL') {
                $this->res->error = ucfirst(strtolower($type));
            }
        }
        // gzip decode
        $encoding = $this->res->getHeader('content-encoding');
        if ($encoding !== null && strstr($encoding, 'gzip')) {
            $this->res->body = Client::gzdecode($this->res->body);
        }
        // parser
        $this->res->timeCost = microtime(true) - $this->timeBegin;
        $this->cli->runParser($this->res, $this->req, $this->key);
        // conn
        if ($this->conn) {
            // close conn
            $close = $this->res->getHeader('connection');
            $this->conn->close($type !== 'NORMAL' || !strcasecmp($close, 'close'));
            $this->conn = null;
            // redirect
            if (($this->res->status === 301 || $this->res->status === 302)
                && $this->res->numRedirected < $this->req->getMaxRedirect()
                && ($location = $this->res->getHeader('location')) !== null
            ) {
                Client::debug('redirect to \'', $location, '\'');
                $req = $this->req;
                if (!preg_match('/^https?:\/\//i', $location)) {
                    $pa = $req->getUrlParams();
                    $url = $pa['scheme'] . '://' . $pa['host'];
                    if (isset($pa['port'])) {
                        $url .= ':' . $pa['port'];
                    }
                    if (substr($location, 0, 1) == '/') {
                        $url .= $location;
                    } else {
                        $url .= substr($pa['path'], 0, strrpos($pa['path'], '/') + 1) . $location;
                    }
                    $location = $url; /// FIXME: strip relative '../../'
                }
                // change new url
                $prevUrl = $req->getUrl();
                $req->setUrl($location);
                if (!$req->getHeader('referer')) {
                    $req->setHeader('referer', $prevUrl);
                }
                if ($req->getMethod() !== 'HEAD') {
                    $req->setMethod('GET');
                }
                $req->clearCookie();
                $req->setHeader('host', null);
                $req->setHeader('x-server-ip', null);
                $req->setHeader('content-type', null);
                $req->setBody(null);
                // reset response
                $this->res->numRedirected++;
                $this->finished = $this->headerOk = false;
                return $this->res->reset();
            }
        }
        Client::debug('finished', $this->res->hasError() ? ' (' . $this->res->error . ')' : '');
        $this->req = $this->cli = null;
    }


    private function readHeader()
    {
        // read header
        while (($line = $this->conn->getLine()) !== null) {
            if ($line === false) {
                return $this->finish('BROKEN');
            }
            if ($line === '') {
                $this->headerOk = true;
                $this->chunkLeft = 0;
                return $this->readBody();
            }
            Client::debug('read header line: ', $line);
            if (!strncmp('HTTP/', $line, 5)) {
                $line = trim(substr($line, strpos($line, ' ')));
                list($this->res->status, $this->res->statusText) = explode(' ', $line, 2);
                $this->res->status = intval($this->res->status);
            } else {
                if (!strncasecmp('Set-Cookie: ', $line, 12)) {
                    $cookie = $this->parseCookieLine($line);
                    if ($cookie !== false) {
                        $this->res->setRawCookie($cookie['name'], $cookie['value']);
                        $this->cli->setRawCookie($cookie['name'], $cookie['value'], $cookie['expires'], $cookie['domain'], $cookie['path']);
                    }
                } else {
                    list($k, $v) = explode(':', $line, 2);
                    $this->res->addHeader($k, trim($v));
                }
            }
        }
    }

    private function readBody()
    {
        // head only
        if ($this->req->getMethod() === 'HEAD') {
            return $this->finish();
        }
        // chunked
        $res = $this->res;
        $conn = $this->conn;
        $length = $res->getHeader('content-length');
        $encoding = $res->getHeader('transfer-encoding');
        if ($encoding !== null && !strcasecmp($encoding, 'chunked')) {
            // unfinished chunk
            if ($this->chunkLeft > 0) {
                $buf = $conn->read($this->chunkLeft);
                if ($buf === false) {
                    return $this->finish('BROKEN');
                }
                if (is_string($buf)) {
                    Client::debug('read chunkLeft(', $this->chunkLeft, ')=', strlen($buf));
                    $res->body .= $buf;
                    $this->chunkLeft -= strlen($buf);
                    if ($this->chunkLeft === 0) {
                        // strip CRLF
                        $res->body = substr($res->body, 0, -2);
                    }
                }
                if ($this->chunkLeft > 0) {
                    return;
                }
            }
            // next chunk
            while (($line = $conn->getLine()) !== null) {
                if ($line === false) {
                    return $this->finish('BROKEN');
                }
                Client::debug('read chunk line: ', $line);
                if (($pos = strpos($line, ';')) !== false) {
                    $line = substr($line, 0, $pos);
                }
                $size = intval(hexdec(trim($line)));
                if ($size <= 0) {
                    while ($line = $conn->getLine()) // tail header
                    {
                        if ($line === '') {
                            break;
                        }
                        Client::debug('read tailer line: ', $line);
                        if (($pos = strpos($line, ':')) !== false) {
                            $res->addHeader(substr($line, 0, $pos), trim(substr($line, $pos + 1)));
                        }
                    }
                    return $this->finish();
                }
                // add CRLF, save to chunkLeft for next loop
                $this->chunkLeft = $size + 2; // add CRLF
                return;
            }
        } else {
            if ($length !== null) {
                $size = intval($length) - strlen($res->body);
                if ($size > 0) {
                    $buf = $conn->read($size);
                    if ($buf === false) {
                        return $this->finish('BROKEN');
                    }
                    if (is_string($buf)) {
                        Client::debug('read fixedBody(', $size, ')=', strlen($buf));
                        $res->body .= $buf;
                        $size -= strlen($buf);
                    }
                }
                if ($size === 0) {
                    return $this->finish();
                }
            } else {
                if ($res->body === '') {
                    $res->setHeader('connection', 'close');
                }
                if (($buf = $conn->read()) === false) {
                    return $this->finish();
                }
                if (is_string($buf)) {
                    Client::debug('read streamBody()=', strlen($buf));
                    $res->body .= $buf;
                }
            }
        }
    }

    private function parseCookieLine($line)
    {
        $now = time();
        $cookie = ['name' => '', 'value' => '', 'expires' => null, 'path' => '/'];
        $cookie['domain'] = $this->req->getHeader('host');
        $parts = explode(';', substr($line, 12));
        foreach ($parts as $part) {
            if (($pos = strpos($part, '=')) === false) {
                continue;
            }
            $k = trim(substr($part, 0, $pos));
            $v = trim(substr($part, $pos + 1));
            if ($cookie['name'] === '') {
                $cookie['name'] = $k;
                $cookie['value'] = $v;
            } else {
                $k = strtolower($k);
                if ($k === 'expires') {
                    $cookie[$k] = strtotime($v);
                    if ($cookie[$k] < $now) {
                        $cookie['value'] = '';
                    }
                } else {
                    if ($k === 'domain') {
                        $pos = strpos($cookie['domain'], $v);
                        if ($pos === 0 || substr($cookie['domain'], $pos, 1) === '.' || substr($cookie['domain'], $pos + 1, 1) === '.') {
                            $cookie[$k] = $v;
                        }
                    } else {
                        if (isset($cookie[$k])) {
                            $cookie[$k] = $v;
                        }
                    }
                }
            }
        }
        if ($cookie['name'] !== '') {
            return $cookie;
        }
        return false;
    }

    private function getRequestBuf()
    {
        // request line
        $cli = $this->cli;
        $req = $this->req;
        $pa = $req->getUrlParams();
        $header = $req->getMethod() . ' ' . $pa['path'];
        if (isset($pa['query'])) {
            $header .= '?' . $pa['query'];
        }
        $header .= ' HTTP/1.1' . Client::CRLF;
        // body (must call prior than headers)
        $body = $req->getBody();
        Client::debug('request body(', strlen($body) . ')');
        // header
        $cli->applyCookie($req);
        foreach (array_merge($cli->getHeader(null), $req->getHeader(null)) as $key => $value) {
            $header .= $this->formatHeaderLine($key, $value);
        }
        Client::debug('request header: ', Client::CRLF, $header);
        return $header . Client::CRLF . $body;
    }

    private function formatHeaderLine($key, $value)
    {
        if (is_array($value)) {
            $line = '';
            foreach ($value as $val) {
                $line .= $this->formatHeaderLine($key, $val);
            }
            return $line;
        }
        if (strpos($key, '-') === false) {
            $line = ucfirst($key);
        } else {
            $parts = explode('-', $key);
            $line = ucfirst($parts[0]);
            for ($i = 1; $i < count($parts); $i++) {
                $line .= '-' . ucfirst($parts[$i]);
            }
        }
        $line .= ': ' . $value . Client::CRLF;
        return $line;
    }
}
