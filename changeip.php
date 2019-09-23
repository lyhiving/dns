<?php
/**
 * checker->lightsail get new ip -> cloudfare/cloudflare partner dns
 *
 * @file    $Source: /README.md  $
 * @package core
 * @author  lyhiving <lyhiving@gmail.com>
 *
 */

$starttime = microtime(true);
$page_title = '';
$version = '1.0.0';

require_once 'settings.php';
if (!defined('HOST_KEY')) {
    exit('Access Denied');
}

use Aws\Exception\AwsException;
use Aws\Lightsail\LightsailClient;
use Cloudflare\Zone\Dns;

$case = isset($_GET['case']) ? $_GET['case'] : 'lightsail';
$host = isset($_GET['host']) ? strtolower($_GET['host']) : '';
$callback = isset($_GET['callback']) ? urldecode($_GET['callback']) : '';

if (!$host) {
    show_json(0, 'host参数不能为空！');
}

if (!isset($_ENV['cfopt']) || !$_ENV['cfopt']) {
    show_json(0, '无Cloudflare配置！');
}

if (!isset($_ENV['aws']) || !$_ENV['aws'] || !$_ENV['aws']['lightsail']) {
    show_json(0, '无Lightsail服务器配置！');
}

if (!$_ENV['aws']['lightsail'][$host]) {
    show_json(0, '无法找到指定服务器配置！');
}

$lsopt = $_ENV['aws']['lightsail'][$host];
$usefake = false;
if (isset($lsopt['realhost']) && $lsopt['realhost']) {
    $host = $lsopt['realhost'];
    $usefake = true;
}
$_ENV['ihost'] = $ihost = $usefake ? $_GET['host'] : $host;

$extract = new LayerShifter\TLDExtract\Extract();

$domainfo = $extract->parse($host);
$domain = $domainfo->getRegistrableDomain();
if (!$domain) {
    show_json(0, '域名格式不正确！');
}

if (isset($_ENV['cfopt']['default']) && $_ENV['cfopt']['default']) {
    $cfopt = $_ENV['cfopt']['default'];
}

if (isset($_ENV['cfopt'][$domain]) && $_ENV['cfopt'][$domain]) {
    $cfopt = $_ENV['cfopt'][$domain];
}

if (isset($_ENV['cfpopt']['default']) && $_ENV['cfpopt']['default']) {
    $cfpopt = $_ENV['cfpopt']['default'];
}

if (isset($_ENV['cfpopt'][$domain]) && $_ENV['cfpopt'][$domain]) {
    $cfpopt = $_ENV['cfpopt'][$domain];
}

if (!$cfopt && !$cfpopt) {
    show_json(0, '无指定Cloudfare/Cloudfare Partner配置！');
}

$client = new Aws\Lightsail\LightsailClient([
    'version' => $_ENV['aws']['version'],
    'region' => $lsopt['region'],
    'credentials' => $_ENV['aws']['credentials'],
]);

try {
    $result = $client->getInstance([
        'instanceName' => $lsopt['vpsid'],
    ]);
    if (!$result['instance']['isStaticIp']) {
        try {
            $result = $client->AttachStaticIp([
                'instanceName' => $lsopt['vpsid'],
                'staticIpName' => $lsopt['ipid'],
            ]);
        } catch (AwsException $e1) {show_json(0, 'Lightsail:  附加静态IP失败。Message: ' . $e1->getMessage());}
    }
    try {
        $result = $client->DetachStaticIp([
            'instanceName' => $lsopt['vpsid'],
            'staticIpName' => $lsopt['ipid'],
        ]);
    } catch (AwsException $e2) {show_json(0, 'Lightsail:  分离静态IP失败。Message: ' . $e2->getMessage());}
    try {
        $result = $client->getInstance([
            'instanceName' => $lsopt['vpsid'],
        ]);
        $ip = $result['instance']['publicIpAddress'];
        if (!$ip) {
            show_json(0, '无法获取新IP！');
        }
// dump($ip);
        switch ($lsopt['dnstype']) {
            case "cf":
                $client = new Cloudflare\Api($cfopt['email'], $cfopt['key']);
                $zones = new Cloudflare\Zone($client);
                $ids = $zones->zones($domain);
                if (!$ids->success) {
                    show_json(0, '获取域名信息失败或改域名不在指定Cloudfare账号下！ ' . ($ids->error ? "Message: " . $ids->error : ""));
                }
                if (!$ids->result || !$ids->result[0] || !$ids->result[0]->id) {
                    show_json(0, '无法获取域名操作ID！ ' . ($ids->error ? "Message: " . $ids->error : ""));
                }
                $dns = new Cloudflare\Zone\Dns($client);
                $olds = $dns->list_records($ids->result[0]->id, 'A', $host);
                if ($olds && $olds->result) {
                    foreach ($olds->result as $k => $v) {
                        if ($v->id) {
                            $dns->delete_record($ids->result[0]->id, $v->id);
                        }

                    }
                }
                $change = $dns->create($ids->result[0]->id, 'A', $host, $ip, 120);
                if (!$change->success) {
                    show_json(0, '更新域名IP出错！IP: ' . $ip . ($usefake ? '' : ' Host: ' . $host) . ' ReqHost: ' . $_GET['host'] . " " . ($change->error ? "Message: " . $change->error : ""));
                }
                show_json(1, array('ip' => $ip, 'host' => $ihost, 'reqhost' => $_GET['host']));
                break;
            case "cfp":
                require_once 'cloudflare.class.php';
                $key = new \Cloudflare\API\Auth\APIKey($cfpopt['email'], $cfpopt['key']);
                $adapter = new Cloudflare\API\Adapter\Guzzle($key);
                $dns = new \Cloudflare\API\Endpoints\DNS($adapter);
                $zones = new \Cloudflare\API\Endpoints\Zones($adapter);
                try {
                    $zoneID = $zones->getZoneID($domain);
                    $dnsresult_data = $dns->listRecords($zoneID);
                    $recordid = 0;
                    if ($dnsresult_data) {
                        $result = $dnsresult_data->result;
                        if ($result) {
                            foreach ($result as $k => $v) {
                                if ($v->name == $host) {
                                    $recordid = $v->id;
                                    $record = $v;
                                    break;
                                }
                            }
                        }
                    } else {
                        $dnsresult_data = array();
                    }
                    $options = ['type' => 'A', 'name' => $host, 'content' => $ip, 'ttl' => 120, 'priority' => 10, 'proxied' => false, 'data' => []];
                    if ($recordid) {
                        try {
                            if ($dns->updateRecordDetails($zoneID, $recordid, $options)) {
                                show_json(1, array('ip' => $ip, 'host' => $ihost, 'reqhost' => $_GET['host']));
                            } else {
                                show_json(0, '更新IP失败. Host:' . $ihost . ' IP:' . $ip);
                            }
                        } catch (Exception $e) {
                            show_json(0, '更新IP失败！ ' . ($e->getMessage() ? "Message: " . $e->getMessage() : ""));
                        }
                    } else {
                        try {
                            $dns = $adapter->post('zones/' . $zoneID . '/dns_records', $options);
                            $dns = json_decode($dns->getBody());
                            if (isset($dns->result->id)) {
                                show_json(1, array('ip' => $ip, 'host' => $ihost, 'reqhost' => $_GET['host']));
                            } else {
                                show_json(0, '创建IP失败. Host:' . $ihost . ' IP:' . $ip);
                            }
                        } catch (Exception $e) {
                            show_json(0, '创建IP失败！ ' . ($e->getMessage() ? "Message: " . $e->getMessage() : ""));
                        }
                    }
                } catch (Exception $e) {
                    show_json(0, '获取域名CFP信息失败或改域名不在指定Cloudfare合作账号下！ ' . ($e->getMessage() ? "Message: " . $e->getMessage() : ""));
                }
                break;
        }

    } catch (AwsException $e3) {show_json(0, 'Lightsail:  获取更新后的实例信息失败。Message: ' . $e3->getMessage());}

} catch (AwsException $e0) {
    show_json(0, 'Lightsail:  首次获取实例信息失败。Message: ' . $e0->getMessage());
}

/**
 * +----------------------------------------------------------
 * 变量输出
 * +----------------------------------------------------------
 * @param string $var 变量名
 * @param string $label 显示标签
 * @param string $echo 是否显示
 * +----------------------------------------------------------
 * @return string
 * +----------------------------------------------------------
 */
function dump($var, $label = null, $strict = true, $echo = true)
{
    $label = ($label === null) ? '' : rtrim($label) . ' ';
    $debug = debug_backtrace();
    $mtime = explode(' ', microtime());
    $ntime = microtime(true);
    $_ENV['dumpOrderID'] = isset($_ENV['dumpOrderID']) && $_ENV['dumpOrderID'] ? $_ENV['dumpOrderID'] + 1 : 1;
    $offtime = !isset($_ENV['dumpTimeCountDown']) || !$_ENV['dumpTimeCountDown'] ? 0 : round(($ntime - $_ENV['dumpTimeCountDown']) * 1000, 4);
    if (!isset($_ENV['dumpTimeCountDown']) || !$_ENV['dumpTimeCountDown']) {
        $_ENV['dumpTimeCountDown'] = $ntime;
    }

    $message = '<br /><font color="#fff" style="width: 30px;height: 12px; line-height: 12px;background-color:' . ($label ? 'indianred' : '#2943b3') . ';padding: 2px 6px;border-radius: 4px;">No. ' . sprintf('%02d', $_ENV['dumpOrderID']) . '</font>&nbsp;&nbsp;' . " ~" . (defined('IA_ROOT') ? substr($debug[0]['file'], strlen(IA_ROOT)) : $debug[0]['file']) . ':(' . $debug[0]['line'] . ") &nbsp;" . date('Y/m/d H:i:s') . " $mtime[0] " . (!$offtime ? "" : "(" . $offtime . "ms)") . '<br />' . PHP_EOL;
    if (!$strict) {
        if (ini_get('html_errors')) {
            $output = print_r($var, true);
            $output = "<pre>" . $label . htmlspecialchars($output, ENT_QUOTES, 'utf-8') . "</pre>";
        } else {
            $output = $label . " : " . print_r($var, true);
        }
    } else {
        ob_start();
        var_dump($var);
        $output = ob_get_clean();
        if (!extension_loaded('xdebug')) {
            $output = preg_replace("/\]\=\>\n(\s+)/m", "] => ", $output);
            $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES, 'utf-8') . '</pre>';
        }
    }
    $output = $message . $output;
    if ($echo) {
        echo ($output);
        return null;
    } else {
        return $output;
    }
}

function show_json($status = 1, $return = null)
{
    global $callback;
    if ($callback) { //回调就增加IP和hostname
        if ($status) {
            $cip = file_get_contents(str_replace(array("[ip]", "[host]", "[reqhost]"), array($return['ip'], $return['host'], $return['reqhost']), $callback));
            $return['callback'] = $cip;
        } else {
            $cip = file_get_contents(str_replace(array("[errmsg]"), array($return), $callback));
        }
    }
    if (defined('PUSHME_KEY')) {
        if ($status) {
            $title = $_ENV['ihost'] . "成功切换IP";
            $content = "新IP地址：" . $ip . PHP_EOL . "解析域名：" . $_ENV['ihost'] . PHP_EOL . ($_GET['host'] == $_ENV['ihost'] ? "" : "请求域名" . $_GET['host']);
        } else {
            $title = $_ENV['ihost'] . "切换IP失败";
            $content = $return;
        }
        $pushret = pushme($title, $content);
        if($status){
            $return['push'] = $pushret;
        }
    }
    if (is_null($status)) {
        @header('Content-type: application/json; charset=UTF-8');
        exit(is_array($return) ? json_encode($return) : $return);
    } else {
        $ret = array('status' => $status, 'result' => array());

        if (!is_array($return)) {
            if ($return) {
                $ret['result']['message'] = $return;
            }

            exit(json_encode($ret));
        } else {
            $ret['result'] = $return;
        }

        if (isset($return['url'])) {
            $ret['result']['url'] = $return['url'];

        }
    }
    exit(json_encode($ret));
}

function pushme($title, $content = '', $key = '')
{
    if (!$key) {
        $key = PUSHME_KEY;
    }

    $postdata = http_build_query(
        array(
            'title' => $title,
            'content' => $content,
        )
    );
    $opts = array('http' => array(
        'method' => 'POST',
        'header' => 'Content-type: application/x-www-form-urlencoded',
        'content' => $postdata,
    ),
    );
    $context = stream_context_create($opts);
    return $result = file_get_contents('https://pushme.tedx.net/' . $key . '.send', false, $context);

}
