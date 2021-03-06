<?php

define('HOST_KEY', 'e9e4498f0584b7098692512db0c62b48');
define('HOST_MAIL', 'ze3kr@example.com');
define('PUSHME_KEY', 'PUSH7dddddddddf3eaef'); //pushmekey
// $page_title = "TlOxygen"; // Optional. Should not use HTML special character.
// $tlo_path = '/'; // Optional. The installation path for this panel, ending with '/'. Required for HTTP/2 Push.
// $is_debug = false; // Enable debug mode

$_ENV['config'] = array(
    'token' => '94a08da1fecbb6e8b46990538c7b50b2', //md5 $_GET['token'] value, example value is token can be null
    'version' => '2016-11-28', //lightsail API date
    'credentials' => array(
        'key' => 'AWS_KEY',
        'secret' => 'AWS_secret'
    ),
    'items' =>array(
        'baidu.com' => array( //you can use a fake domain
            'realhost' => 'xxx.realdomain.com', //This is real domain
            'vpsid' => 'InstanceID', //Lightsail Instance ID
            'ipid'  => 'IPID', //Lightsail Static IP ID, Must be same region with Lightsai Instance
            'region' => 'ap-northeast-1', // Lightsail Region 
            'dnstype' =>'cf', //realhost or domain dns type. Support cloudflare->cf, AWS -> aws
            'callbacks' => array(//multi callbacks [DNS] stand for this site url, [token]\[ip]\[host]\[reqhost]\[errmsg]
                '[DNS]/changeip.php?host=ons.no&token=[token]' 
            )
        )
    )
);
$_ENV['cfopt'] = array(//CloudFlare Option
    'default' => array( //Default Cloudflare
        'email' =>'cfdomain', //CF Account ID
        'key'   => 'cfkey' //MUST BE CF account global KEY
    ),
    'realdomain.com' => array(
        'email' =>'cfdomain', //CF Account ID
        'key'   => 'cfkey' //MUST BE CF account global KEY
    )
);

$_ENV['cfpopt'] = array(//CloudFlare Partner Option
    'default' => array( //Default Cloudflare
        'email' =>'cfdomain', //CF Account ID
        'key'   => 'cfkey' //MUST BE CF account global KEY
    ),
    'realdomain.com' => array(
        'email' =>'cfdomain', //CF Account ID
        'key'   => 'cfkey' //MUST BE CF account global KEY
    )
);

$_ENV['namecheapopt'] = array(//Namecheap
    'domain.com'=> array( //speicaildomain or default
        'username' =>'username', //Namecheap username
        'key'   => 'APIKEY', //Namecheap API KEY
        'ip'    => '1.1.1.x'  //If no set IP will get the server IP automatic. Local TEST use namecheap.ip file content
    )
);