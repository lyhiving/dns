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
$extract = new LayerShifter\TLDExtract\Extract();

$token = isset($_GET['token']) ? $_GET['token'] : 'token';
$case = isset($_GET['case']) ? $_GET['case'] : 'lightsail';
if($case && $case=='ping'){
    exit('PONG!');
}
if($_ENV['config'] && $_ENV['config']['token']){
    if(!$token || ($token!=$_ENV['config']['token'] && md5($token)!=$_ENV['config']['token'])){
        exit('Token Access Denied');
    }
}

$host = isset($_GET['host']) ? strtolower($_GET['host']) : '';

if (!$host) {
    show_json(0, $host.'host参数不能为空！');
}

if (!isset($_ENV['config']) || !$_ENV['config'] || !$_ENV['config']['items']) {
    show_json(0, $host.'无Lightsail服务器配置！');
}

if (!$_ENV['config']['items'][$host]) {
    show_json(0, $host.'无法找到指定服务器配置！');
}

$lsopt = $_ENV['config']['items'][$host];
$usefake = false;
if (isset($lsopt['realhost']) && $lsopt['realhost']) {
    $host = $lsopt['realhost'];
    $usefake = true;
}
$dnstype = $lsopt['dnstype'];
$callbacks = isset($lsopt['callbacks']) ? $lsopt['callbacks'] : '';
$dnsopt = $_ENV[$dnstype.'opt'];

$_ENV['ihost'] = $ihost = $usefake ? $_GET['host'] : $host;
$_ENV['host'] = $host;

$domainfo = $extract->parse($host);
$domain = $domainfo->getRegistrableDomain();
if (!$domain) {
    show_json(0, $host.'域名格式不正确！');
}
$dnsconfig = array();
if (isset($dnsopt['default']) && $dnsopt['default']) {
    $dnsconfig = $dnsopt['default'];
}

if (isset($dnsopt[$domain]) && $dnsopt[$domain]) {
    $dnsconfig = $dnsopt[$domain];
}
if($dnstype=='lightsail' && !$dnsconfig && $_ENV['config']['credentials']){//lightsail 允许重用配置
    $dnsconfig = $_ENV['config']['credentials'];
}

if (!$dnsconfig) {
    show_json(0, '无指定DNS('.$dnstype.')配置！');
}
$ip = '';
if (isset($lsopt['toip']) && $lsopt['toip']) {
    $ip = $lsopt['toip'];
}

if(!$ip){
    $client = new Aws\Lightsail\LightsailClient([
        'version' => $_ENV['config']['version'],
        'region' => $lsopt['region'],
        'credentials' => $_ENV['config']['credentials'],
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

        } catch (AwsException $e3) {show_json(0, 'Lightsail:  获取更新后的实例信息失败。Message: ' . $e3->getMessage());}

    } catch (AwsException $e0) {
        show_json(0, 'Lightsail:  首次获取实例信息失败。Message: ' . $e0->getMessage());
    }
}

if (!$ip) {
    show_json(0, '无法获取新IP！');
}

switch ($dnstype) {
    case "lightsail":
        $client = new Aws\Lightsail\LightsailClient([
            'version' => $_ENV['config']['version'],
            'region' => 'us-east-1',
            'credentials' => $dnsconfig,
        ]);
        try {
            $result = $client->getDomain(array('domainName'=>$domain));
            $domainEntries = $result['domain']['domainEntries'];
            foreach($domainEntries as $r){
                if($r['type']=='A' && $r['name']==$host){
                    $r['target'] = $ip;
                    $domainEntry = array('domainEntry'=>$r,'domainName'=>$domain);
                    try {
                        $ret = $client->UpdateDomainEntry($domainEntry);
                        if($ret['operations'][0]['status']=='Succeeded'){
                            show_json(1, array('ip' => $ip, 'host' => $ihost, 'reqhost' => $_GET['host']));
                        }else{
                            show_json(0, '更新Lightsail域名记录有误，服务器返回错误。');
                        }
                    } catch (AwsException $e1) {show_json(0, '更新Lightsail域名记录失败。Message: ' . $e1->getMessage());}
                }
            }
        }catch (Exception $e) {
            show_json(0, '无法获取Lightsail域名'.$domain.'的DNS记录，请确保连接的是us-east-1区域！ ' . ($e->getMessage() ? "Message: " . $e->getMessage() : ""));
        }
    break;
    case "cf":
        $client = new Cloudflare\Api($dnsconfig['email'], $dnsconfig['key']);
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
        $key = new \Cloudflare\API\Auth\APIKey($dnsconfig['email'], $dnsconfig['key']);
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
    case "namecheap":
        $apiUser = $userName = $dnsconfig['username'];
        $apiKey = $dnsconfig['key'];
        if(!$dnsconfig['ip']){
            if(is_file(ROOT_PATH.'namecheap.ip.txt')){
                $clientIp = file_get_contents(ROOT_PATH.'namecheap.ip.txt');
            }else{
                $clientIp = get_server_ip();
            }
        }else{
            $clientIp = $dnsconfig['ip'];
        }
        $returnType = 'array';
        $ncDomainsDns = new  Namecheap\Domain\DomainsDns($apiUser, $apiKey, $userName, $clientIp, $returnType); 
        $ncDomainsDns->setCurlOption(CURLOPT_SSL_VERIFYPEER, false); 
        try {
            $list = $ncDomainsDns->getHosts($domainfo->hostname, $domainfo->suffix);
            $hostlist = $list['ApiResponse']['CommandResponse']['DomainDNSGetHostsResult']['host'];
            if($list && $hostlist){
                $i=1;
                foreach($hostlist as $r){
                    $up['name']['HostName'.$i] = $r['-Name'];
                    $up['type']['RecordType'.$i] = $r['-Type'];
                    $up['address']['Address'.$i] = $r['-Name']== $domainfo->subdomain ? $ip: $r['-Address'];
                    $up['mxpref']['MXPref'.$i] = $r['-MXPref'];
                    $up['ttl']['TTL'.$i] = $r['-Name']==$domainfo->subdomain ? '60': $r['-TTL'];
                    $i++;
                }
                try {
                    $ret = $ncDomainsDns->setHosts($domainfo->hostname, $domainfo->suffix,$up['name'],$up['type'],$up['address'], $up['mxpref'],null,$up['ttl']);
                    $msg = $ret['ApiResponse']['Errors'];
                    if(!$ret||$msg){
                        show_json(0, '更新NameCheap的DNS记录出错！ ' . $msg);
                    }else{
                        show_json(1, array('ip' => $ip, 'host' => $ihost, 'reqhost' => $_GET['host']));
                    }
                } catch (Exception $e) {
                    show_json(0, '更新NameCheap的DNS记录出错，请检查配置！ ' . ($e->getMessage() ? "Message: " . $e->getMessage() : ""));
                }
            }else{
                show_json(0, 'NameCheap的DNS记录为空！' );
            }
        }catch (Exception $e) {
            show_json(0, '无法获取NameCheap的DNS记录，请检查IP是否在列表中！ ' . ($e->getMessage() ? "Message: " . $e->getMessage() : ""));
        }
    break;
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
    global $callbacks, $host;
    if ($callbacks) { //回调就增加IP和hostname
        foreach($callbacks as $callback){
            $url = str_replace(array("[DNS]", "[ip]", "[host]", "[reqhost]", "[token]","[errmsg]"), array('http://' . $_SERVER['HTTP_HOST'],$return['ip'], $return['host'], $host, $_ENV['config']['token'], $return), $callback);
            $cip = file_get_contents($url);
            if ($status) {
                $return['callback'][] = $cip;
            }
        }
    }
    if (defined('PUSHME_KEY')) {
        if ($status) {
            $title = $_ENV['ihost'] . "成功切换IP";
            $content = "新IP地址：" . $return['ip']  . PHP_EOL . "请求标记：" . $return['host'] . PHP_EOL . ($return['host'] == $host ? "" : "解析域名：" . $host).PHP_EOL ."（成功更新IP并不意味可以正常访问，如约10分钟后无IP更新提示则说明已生效。期间可以测试访问）";
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


/**
 * 获取客户端IP地址
 * @return string
 */
function get_client_ip() {
    if(getenv('HTTP_CLIENT_IP')){
        $client_ip=getenv('HTTP_CLIENT_IP');
    }elseif(getenv('HTTP_X_FORWARDED_FOR')) {
        $client_ip=getenv('HTTP_X_FORWARDED_FOR');
    }elseif(getenv('REMOTE_ADDR')) {
        $client_ip=getenv('REMOTE_ADDR');
    }else{
        $client_ip=$_SERVER['REMOTE_ADDR'];
    }
    return $client_ip;
} 

 
/**
* 获取服务器端IP地址
 * @return string
 */
function get_server_ip() {
    if(isset($_SERVER)) {
        if($_SERVER['SERVER_ADDR']) {
            $server_ip=$_SERVER['SERVER_ADDR'];
        }else{
            $server_ip=$_SERVER['LOCAL_ADDR'];
        }
    }else{
        $server_ip=getenv('SERVER_ADDR');
    }
    return $server_ip;
}