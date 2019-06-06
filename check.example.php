<?php

$wxkey = 'FTQQ_PUSH_KEY';

if($wxkey=='FTQQ_PUSH_KEY'){
    exit('Please modify your own check.php base form check.example.php ');
}
check_port('CHECK_HOST', 'CHECK_PORT','PUSH_IP_URL');

function main_handler($event, $context) {
}


function check_port($ip, $port,  $pushurl = '')
{
    global $wxkey;
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_set_nonblock($sock);
    socket_connect($sock, $ip, $port);
    socket_set_block($sock);
    $return = @socket_select($r = array($sock), $w = array($sock), $f = array($sock), 3);
    socket_close($sock);
    switch ($return) {
        case 2:
            echo "$ip:$port 关闭\n";
            sc_send("远程端口已关闭。 位置：" . $ip . ":" . $port);
            break;
        case 1:
            echo "$ip:$port 打开\n";
            break;
        case 0:
            echo "$ip:$port 超时\n";
            $title = "连接：$ip 端口：$port 出现超时";
            $msg = '准备重启';
            if ($pushurl) {
                $meta = wget($pushurl);
                if (!$meta) {
                    $msg = '更改IP失败，连接失败。 URL: ' . $pushurl;
                } else {
                    $meta = json_decode($meta);
                    if (!$meta || !is_array($meta)) {
                        $msg = '更改IP失败，内容不正确。 URL: ' . $pushurl;
                    } else {
                        if (!$meta['status']) {
                            $msg = '更改IP失败，错误信息: ' . $meta['result'] . " 。 URL: " . $pushurl;
                        } else {
                            $msg = '成功更改IP: ' . $meta['result']['ip'] . " 。 URL: " . $pushurl;
                        }
                    }
                }
            } else {
                $msg = '无IP推送地址';
            }
            sc_send($title, $msg, $wxkey);
            break;
    }

}

function wget($url, $timeout = 10, $post = null)
{
    $context = array();
    if (is_array($post)) {
        ksort($post);
        $context['http'] = array(
            'timeout' => $timeout,
            'method' => 'POST',
            'content' => http_build_query($post, '', '&'),
        );

    }
    return file_get_contents($url, false, stream_context_create($context));
}

function sc_send($text, $desp = '',  $wxkey='')
{
    $postdata = http_build_query(
        array(
            'text' => $text,
            'desp' => $desp,
        )
    );

    $opts = array('http' => array(
        'method' => 'POST',
        'header' => 'Content-type: application/x-www-form-urlencoded',
        'content' => $postdata,
    ),
    );
    $context = stream_context_create($opts);
    return $result = file_get_contents('https://sc.ftqq.com/' . $wxkey . '.send', false, $context);

}
