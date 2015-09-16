<?php
/**
 * Demo for the parallel HTTP client
 *
 * @author hightman <hightman@twomice.net>
 * @link http://hightman.cn
 * @copyright Copyright (c) 2015 Twomice Studio.
 */

// import library file
require_once 'httpclient.inc.php';

use hightman\http\Client;
use hightman\http\Request;
use hightman\http\Response;

// create client instance
$http = new Client();

// set cookie file
$http->setCookiePath('cookie.dat');

// add text/plain header for web sapi handler
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
}

// simple load response contents
echo '1. loading content of baidu ... ';
$response = $http->get('http://www.baidu.com');
echo number_format(strlen($response)) . ' bytes', PHP_EOL;

echo '2. fetching search results of `php\' in baidu ... ';
$response = $http->get('http://www.baidu.com/s', ['wd' => 'php']);
echo number_format(strlen($response)) . ' bytes', PHP_EOL;
echo '   time costs: ', $response->timeCost, PHP_EOL;
echo '   response.status: ', $response->status, ' ', $response->statusText, PHP_EOL;
echo '   response.headers.content-type: ', $response->getHeader('content-type'), PHP_EOL;
echo '   response.headers.transfer-encoding: ', $response->getHeader('transfer-encoding'), PHP_EOL;
echo '   response.cookies.BDSVRTM: ', $response->getCookie('BDSVRTM'), PHP_EOL;

echo '3. testing error response ... ';
$response = $http->get('http://127.0.0.1:65535');
echo $response->hasError() ? 'ERROR: ' . $response->error : 'OK', PHP_EOL;

echo '4. test head request to baidu ... ';
$response = $http->head('http://www.baidu.com');
echo number_format(strlen($response)) . ' bytes', PHP_EOL;
echo '   response.headers.server: ', $response->getHeader('server'), PHP_EOL;

echo '5. post request to baidu ... ';
//Client::debug('open');
$response = $http->post('http://www.baidu.com/s', ['wd' => 'php', 'ie' => 'utf-8']);
echo number_format(strlen($response)) . ' bytes', PHP_EOL;
if ($response->hasError()) {
    echo '   response.error: ', $response->error, PHP_EOL;
}

echo '6. testing postJSON request ...';
$data = $http->postJson('http://api.mcloudlife.com/api/version');
echo 'OK', PHP_EOL;
echo '   response.json: ', json_encode($data), PHP_EOL;

echo '7. customize request for restful API ... ';
$request = new Request('http://api.mcloudlife.com/open/record/bp/1024');
$request->setMethod('GET');
$request->setHeader('user-agent', 'mCloud/2.4.3D');
// bearer token authorization
$request->setHeader('authorization', 'Bearer f4fe27fe5f270a4e8edc1a07289452d1');
$request->setHeader('accept', 'application/json');
$response = $http->exec($request);
if ($response->hasError()) {
    echo 'ERROR', PHP_EOL;
    echo '   response.error: ', $response->error, PHP_EOL;
} else {
    echo 'OK', PHP_EOL;
    echo '   response.status: ', $response->status, ' ', $response->statusText, PHP_EOL;
    echo '   response.body: ', $response->body, PHP_EOL;
}

echo '8. post request & upload files ... ';
$request = new Request('http://hightman.cn/post.php');
$request->setMethod('POST');
$request->setHeader('x-server-ip', '202.75.216.234');
$request->addPostField('post1', 'post1-value');
$request->addPostField('post2', 'post2-value');
$request->addPostFile('upload1', 'upload1-name.txt', 'hi, just a test');
$request->addPostFile('upload2', __FILE__);
$response = $http->exec($request);
echo number_format(strlen($response)) . ' bytes', PHP_EOL;
echo $response, PHP_EOL;

echo '9. multiple get requests in parallel ... ', PHP_EOL;

// define callback as normal function
function test_cb($res, $req, $key)
{
    echo '   ', $req->getUrl(), ', ', number_format(strlen($res)), ' bytes in ', sprintf('%.4f', $res->timeCost), 's', PHP_EOL;
    // even you can redirect HERE
    if ($key === 'baidu' && !strstr($req->getUrl(), 'czxiu')) {
        $res->redirect('http://www.czxiu.com');
    }
}

$http->setParser('test_cb');
$responses = $http->mget([
    'baidu' => 'http://www.baidu.com',
    'sina' => 'http://news.sina.com.cn',
    'qq' => 'http://www.qq.com',
]);

echo '10. process multiple various requests in parallel ... ', PHP_EOL;

// define callback as object
class testCb implements \hightman\http\ParseInterface
{
    public function parse(Response $res, Request $req, $key)
    {
        echo '    ', $req->getMethod(), ' /', $key, ' finished, ', number_format(strlen($res)), ' bytes in ', sprintf('%.4f', $res->timeCost), 's', PHP_EOL;
    }
}

// construct requests
$requests = [];
$requests['version'] = new Request('http://api.mcloudlife.com/api/version', 'POST');
$requests['baidu'] = new Request('http://www.baidu.com/s?wd=php');

$request = new Request('http://api.mcloudlife.com/open/auth/token');
$request->setMethod('POST');
$request->setHeader('accept', 'application/json');
$request->setJsonBody([
    'client_id' => 'client_id',
    'client_secret' => 'client_secret',
]);
$requests['token'] = $request;

$http->setParser(new testCb());
$responses = $http->exec($requests);
