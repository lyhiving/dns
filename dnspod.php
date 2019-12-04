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

require_once dirname(__FILE__) . '/vendor/autoload.php';
$uid = 'dnspodID';
$token = 'dnspod密钥';
ini_set('display_errors',true);
error_reporting(E_ALL);

use dnspod_api\Dnspod;

$DP = new Dnspod($uid, $token);
// $DP->develop = true;
$records = file_get_contents(__DIR__.'/aliyun_domain.json');
$records = json_decode($records, true);
if(is_array($records) && $records){
    $domain = 'domain.com';
    foreach($records as $r){
        $p = $DP->addRecord($domain, $r['RR'], $r['Value'], $r['Type'], array('status'=>strtolower($r['Status']),'ttl'=>$r['TTL']), $r['Remark']);
        dump(array('p'=>$p,'r'=>$r));
    }
}
dump(__LINE__);
// $copy =  $DP->copyRecords('domain.com','tedx.cn');
dump($copy);
exit;
$ret = $DP->getRecordList('domain.com', array('length'=>100));
$total = $ret['info']['record_total'];
$records = $ret['records'];
dump($records);
exit;
$share_email = '2115077380@qq.com';
foreach($domains as $r){
    $ret = $DP->shareRemove($r['name'], $share_email);
    dump($r['name']);
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
    return $result = file_get_contents('https://pushme.domain.com/' . $key . '.send', false, $context);

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