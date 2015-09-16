A parallel HTTP client written in pure PHP
==========================================

This is a powerful HTTP client written in pure PHP code, dose not require any other
PHP extension. It help you easy to send HTTP request and handle its response.

- Process multiple requests in parallel
- Full support for HTTP methods, including GET, POST, HEAD, ...
- Customizable HTTP headers, full support for Cookie, X-Server-Ip
- Follows 301/302 redirect, can set the maximum times
- Supports Keep-Alive, reuse connection to the same host
- Supports HTTPS with openssl
- Allow to upload file via POST method
- Detailed information in DEBUG mode
- Free and open source, release under MIT license


Requirements
-------------

PHP >= 5.4.0


Install
-------

### Install from an Archive File

Extract the archive file downloaded from [httpclient-master.zip](https://github.com/hightman/httpclient/archive/master.zip)
to your project. And then add the library file into your program:

```php
require '/path/to/httpclient.inc.php';
```

### Install via Composer

If you do not have [Composer](http://getcomposer.org/), you may install it by following the instructions
at [getcomposer.org](http://getcomposer.org/doc/00-intro.md#installation-nix).

You can then install this library using the following command:

~~~
php composer.phar require "hightman/httpclient:*"
~~~


Usage
-------

### Quick to use


We have defined some shortcut methods, they can be used as following:

```php
use hightman\http\Client;

$http = new Client();

// 1. display response contents
echo $http->get('http://www.baidu.com');
echo $http->get('http://www.baidu.com/s', ['wd' => 'php']);

// 2. capture the response object, read the meta information
$res = $http->get('http://www.baidu.com');
print_r($res->getHeader('content-type'));
print_r($res->getCookie(null));

// 3. post request
$res = $http->post('http://www.your.host/', ['field1' => 'value1', 'field2' => 'value2']);
if (!$res->hasError()) {
   echo $res->body;    // response content
   echo $res->status;  // response status code
}

// 4. head request
$res = $http->head('http://www.baidu.com');
print_r($res->getHeader(null));

// delete request
$res = $http->delete('http://www.your.host/request/uri');

// 5. restful json requests
// there are sismilar api like: postJson, putJson
$data = $http->getJson('http://www.your.host/request/uri');
print_r($data);

$data = $http->postJson('http://www.your.host/reqeust/uri', ['key1' => 'value1', 'key2' => 'value2']);

```

### Customize request

You can also customize various requests by passing in `Request` object.

```php
use hightman\http\Client;
use hightman\http\Request;

$http = new Client();
$request = new Request('http://www.your.host/request/uri');

// set method
$request->setMethod('POST');
// add headers
$request->setHeader('user-agent', 'test robot');

// specify host ip, this will skip DNS resolver
$request->setHeader('x-server-ip', '1.2.3.4');

// add post fields
$request->addPostField('name', 'value');
$request->addPostFile('upload', '/path/to/file');
$request->addPostFile('upload_virtual', 'virtual.text', 'content of file ...');

// or you can specify request body directly
$request->setBody('request body ...');

// you also can specify JSON data as request body
// this will set content-type header to 'application/json' automatically.
$request->setJsonBody(['key' => 'value']);

// execute the request
$response = $http->exec($request);
print_r($response);

```


### Multiple get in parallel

A great features of this library is that we can execute multiple requests in parallel.
For example, executed three requests simultaneously, the total time spent is one of the longest,
rather than their sum.


```php
use hightman\http\Client;
use hightman\http\Request;
use hightman\http\Response;

// Define callback as function, its signature:
// (callback) (Response $res, Request $req, string|integer $key);
function test_cb($res, $req, $key)
{
   echo '[' . $key . '] url: ' . $req->getUrl() . ', ';
   echo 'time cost: ' . $res->timeCost . ', size: ' . number_format(strlen($res->body)) . "\n";
}

// or you can define callback as a class implemented interface `ParseInterface`.
class testCb implements \hightman\http\ParseInterface
{
  public function parse(Response $res, Request $req, $key)
  {
    // your code here ...
  }
}

// create client object with callback parser
$http = new \hightman\http\Client('test_cb');

// or specify later as following
$http->setParser(new testCb);

// Fetch multiple URLs, it returns after all requests are finished.
// It may be slower for the first time, because of DNS resolover.
$results = $http->mget([
  'baidu' => 'http://www.baidu.com/',
  'sina' => 'http://news.sina.com.cn/',
  'qq' => 'http://www.qq.com/',
]);

// show all results
// print_r($results);

```

> Note: There are other methods like: mhead, mpost, mput ...
> If you need handle multiple different requests, you can pass an array of `Request`
> objects into `Client::exec($reqs)`.


### Export and reused cookies


This library can intelligently manage cookies, default store cookies in memory and send them on need.
We can export all cookies after `Client` object destoried.

```php
$http->setCookiePath('/path/to/file');
```

### Add bearer authorization token

```php
$http->setHeader('authorization', 'Bearer ' . $token);
// or add header for request object
$request->setHeader('authorization', 'Bearer ' . $token);
```


### Enable debug mode

You can turn on debug mode via `Client::debug('open')`.
This will display many debug messages to help you find out problem.


### Others

Because of `Client` class also `use HeaderTrait`, you can use `Client::setHeader()`
to specify global HTTP headers for requests handled by this client object.


Contact me
-----------

If you have any questions, please report on github [issues](https://github.com/hightman/httpclient/issues)
