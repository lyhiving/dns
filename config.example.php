<?php

define('HOST_KEY', 'e9e4498f0584b7098692512db0c62b48');
define('HOST_MAIL', 'ze3kr@example.com');
// $page_title = "TlOxygen"; // Optional. Should not use HTML special character.
// $tlo_path = '/'; // Optional. The installation path for this panel, ending with '/'. Required for HTTP/2 Push.
// $is_debug = false; // Enable debug mode

$_ENV['aws'] = array(
    'version' => '2016-11-28', //lightsail API date
    'credentials' => array(
        'key' => 'AWS_KEY',
        'secret' => 'AWS_secret'
    ),
    'lightsail' =>array(
        'baidu.com' => array( //you can use a fake domain
            'realhost' => 'xxx.realdomain.com', //This is real domain
            'vpsid' => 'InstanceID', //Lightsail Instance ID
            'ipid'  => 'IPID', //Lightsail Static IP ID, Must be same region with Lightsai Instance
            'region' => 'ap-northeast-1' // Lightsail Region
        )
    )
);
$_ENV['cfopt'] = array(
    'default' => array( //Default Cloudflare
        'email' =>'cfdomain', //Only use for lightsail problem
        'key'   => 'cfkey' //Only use for lightsail problem
    ),
    'realdomain.com' => array(
        'email' =>'cfdomain', //Only use for lightsail problem
        'key'   => 'cfkey' //Only use for lightsail problem
    )
);