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
 * Interface for classes that parse the HTTP response.
 *
 * @author hightman
 * @since 1.0
 */
interface ParseInterface
{
    /**
     * Parse HTTP response
     * @param Response $res the resulting response
     * @param Request $req the request to be parsed
     * @param string $key the index key of request
     */
    public function parse(Response $res, Request $req, $key);
}
