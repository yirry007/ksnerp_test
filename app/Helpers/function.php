<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 路由可能是http，也可能是https，这里设置，全局使用
 * @param $path
 * @return \Illuminate\Contracts\Routing\UrlGenerator|\Illuminate\Foundation\Application|string
 */
function _url_($path)
{
    return url($path);
    //return secure_url($path);
}

/**
 * 判断数组中某个key对应的值是否有效，有效则true，否则为false
 * @param $key
 * @param $array
 * @return bool
 */
function array_member($key, $array)
{
    if (array_key_exists($key, $array) && $array[$key] !== null && $array[$key] !== '') {
        return true;
    } else {
        return false;
    }
}

/**
 * 判断数组中是否有某个key，如果有则返回该key对应的值，否则返回空字符串或0
 * @param $key
 * @param $array
 * @param bool $is_num
 * @return string
 */
function array_val($key, $array, $is_num=false)
{
    if ($is_num)
        return array_key_exists($key, $array) ? $array[$key] : '0';
    else
        return array_key_exists($key, $array) ? $array[$key] : '';
}

/**
 * 验证是否合法的 ip 地址
 * @param $ip string
 * @return bool
 */
function isValidIP($ip) {
    $pattern = '/^((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.){3}(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/';
    if (preg_match($pattern, $ip)) {
        return true;
    } else {
        return false;
    }
}

/**
 * 管理员密码加密
 * @param String $str
 * @return String
 */
function pwdEnc($str)
{
    return md5(md5(env('PWD_PREFIX').$str).env('PWD_SUFIX'));
}

/**
 * 获取 token 解码数据
 * sub => users.username,
 * iat => token create time,
 * iss => application url,
 * jti => users.id,
 * aud => users.parent_id,
 * paths => user_menu | 用户所拥有的权限,
 * @return stdClass
 */
function getTokenPayload()
{
    $schema = 'Bearer';
    $header = request()->header('Authorization');
    $token = trim(ltrim($header, $schema));

    return JWT::decode($token, new Key(Config::get('token.api_key'), Config::get('token.jwt_alg')));
}

/**
 * yahoo 授权加密字符串
 * @param $shop
 * @return mixed
 */
function getYahooEncAuthVal($shop)
{
    $authenticationValue = $shop->shop_id . ":" . time();
    $publicKey = openssl_pkey_get_public($shop->public_key);
    openssl_public_encrypt($authenticationValue, $encryptedAuthenticationValue, $publicKey);

    return base64_encode($encryptedAuthenticationValue);
}

/**
 * 代理服务器ip数据转换 （xxx.xxx.xxx.xxx:yy => ['ip'=>'xxx.xxx.xxx.xxx', 'port'=>'yy']）
 * @param $ip
 * @return array
 */
function getProxyInfo($ip)
{
    $return = array();

    $proxyInfo = explode(':', $ip);

    $return['ip'] = array_key_exists(0, $proxyInfo) ? $proxyInfo[0] : '';
    $return['port'] = array_key_exists(1, $proxyInfo) ? $proxyInfo[1] : '3128';

    return $return;
}

/**
 * 后台运行进程，返回 Linux 进程 ID（php 命令获取不到 Linux 进程编号）
 * @param $cmd
 * @return int
 */
function execInBackground($cmd)
{
    $output = [0];

    if (substr(php_uname(), 0, 7) == "Windows"){
        pclose(popen("start /B ". $cmd, "r"));
    } else {
        exec($cmd . " > /dev/null & echo $!", $output);
    }

    return $output[0];
}

/**
 * url 中添加 query 参数
 * @param $url
 * @param $query
 * @return string
 */
function setQuery($url, $query)
{
    return $url . '?' . http_build_query($query);
}

/**
 * xml -> array
 * @param string $xml
 * @return mixed
 */
function xmlToArray($xml)
{
    $xml_parser = xml_parser_create();
    if (!xml_parse($xml_parser,$xml,true)) {
        return [
            'code' => 'E6011',
            'message' => 'XML data format error. ' . (string)$xml,
        ];
    }

    $xmlObj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    $jsonString = json_encode($xmlObj);
    return json_decode($jsonString, true);
}

/**
 * array -> xml
 * @param $arr
 * @param string $root
 * @param bool $dom
 * @param bool $item
 * @return false|string
 */
function arrayToXml($arr, $root='root', $dom=false, $item=false)
{
    if (!$dom) {
        $dom = new DOMDocument("1.0");
    }
    if (!$item) {
        $item = $dom->createElement($root);
        $dom->appendChild($item);
    }
    foreach ($arr as $key => $val) {
        $itemx = $dom->createElement(is_string($key) ? $key : "item");
        $item->appendChild($itemx);
        if (!is_array($val)) {
            $text = $dom->createTextNode($val);
            $itemx->appendChild($text);
        } else {
            arrayToXml($val, $key, $dom, $itemx);
        }
    }
    return $dom->saveXML();
}

/**
 * @param string $data 待加密字符串
 * @param string $key 密钥
 * @return string
 */
function enc($data, $key = 'encrypt')
{
    $key = md5($key);
    $x = 0;
    $len = strlen($data);
    $l = strlen($key);
    $char = '';
    for ($i = 0; $i < $len; $i++) {
        if ($x == $l) {
            $x = 0;
        }
        $char .= $key[$x];
        $x++;
    }
    $str = '';
    for ($i = 0; $i < $len; $i++) {
        $str .= chr(ord($data[$i]) + (ord($char[$i])) % 256);
    }
    return base64_encode($str);
}

/**
 * @param string $data 待解密字符串
 * @param string $key 密钥
 * @return string
 */
function dec($data, $key = 'encrypt')
{
    $key = md5($key);
    $x = 0;
    $data = base64_decode($data);
    $len = strlen($data);
    $l = strlen($key);
    $char = '';
    for ($i = 0; $i < $len; $i++) {
        if ($x == $l) {
            $x = 0;
        }
        $char .= substr($key, $x, 1);
        $x++;
    }
    $str = '';
    for ($i = 0; $i < $len; $i++) {
        if (ord(substr($data, $i, 1)) < ord(substr($char, $i, 1))) {
            $str .= chr((ord(substr($data, $i, 1)) + 256) - ord(substr($char, $i, 1)));
        } else {
            $str .= chr(ord(substr($data, $i, 1)) - ord(substr($char, $i, 1)));
        }
    }
    return $str;
}

/**
 * 配置邮件服务器，模板，数据后发送邮件
 * @param array $config 邮件服务配置
 * @param array $data 邮件模板数据
 */
function sendEmail($config, $html)
{
    $configName = 'ksn';

    Config::set([
        "mail.mailers.{$configName}.transport"=>$config['transport'] ?? 'smtp',
        "mail.mailers.{$configName}.host"=>$config['host'],
        "mail.mailers.{$configName}.port"=>$config['port'],
        "mail.mailers.{$configName}.encryption"=>$config['encryption'] ?? 'ssl',
        "mail.mailers.{$configName}.username"=>$config['username'],
        "mail.mailers.{$configName}.password"=>$config['password'],
        "mail.mailers.{$configName}.timeout"=>null,
        "mail.mailers.{$configName}.auth_mode"=>null,
    ]);

    Mail::mailer($configName)->html($html, function($message) use ($config) {
        $message->to($config['to'], $config['receiver'] ?? $config['to']);
        $message->subject($config['subject'] ?? '');
        $message->from($config['from'] ?? $config['username'], $config['sender'] ?? $config['username']);
    });
}

/**
 * 物流公司名称和 Services.Delivery 模块映射表
 * @param $shippingCompany
 * @return string
 */
function shippingCompanyMap($shippingCompany)
{
    $map = [
        '佐川急便'=>'Sagawa',
        'ヤマト運輸'=>'Yamato',
        '日本郵便'=>'JapanPost',
    ];

    return $map[trim($shippingCompany)] ?? '';
}

/**
 * 获取当前服务器的私有IP地址
 * @return string
 */
function getServerPrivateIP()
{
    if (array_key_exists('SSH_CONNECTION', $_SERVER) && $_SERVER['SSH_CONNECTION']) {
        $ipInfo = explode(' ', $_SERVER['SSH_CONNECTION']);

        if (count($ipInfo) >= 3) return $ipInfo[2];
    }

    if (array_key_exists('HOSTNAME', $_SERVER) && $_SERVER['HOSTNAME']) {
        $ipInfo = explode('.', $_SERVER['HOSTNAME']);

        return str_replace('-', '.', ltrim($ipInfo[0], 'ip-'));
    }

    $uname = php_uname();
    $unameInfo = explode(' ', $uname);
    if (count($unameInfo) >= 2) {
        $ipInfo = explode('.', $unameInfo[1]);

        return str_replace('-', '.', ltrim($ipInfo[0], 'ip-'));
    }

    return '';
}

/**
 * 从缓存（或数据库）获取语言包
 * @param string $code
 * @return mixed
 */
function getLanguage($code = 'cn')
{
    $lang = Cache::get('lang');
    if (!$lang) {
        $languages = DB::table('languages')->get();
        $lang = array();
        foreach ($languages as $v) {
            $lang[$v->code] = json_decode($v->language, true);
        }

//        $saveTime = 60 * 24 * 7;//缓存中保存7天
        $saveTime = 1;//缓存中保存1分钟
        Cache::add('lang', str_replace('\\', '', json_encode($lang, JSON_UNESCAPED_UNICODE)), $saveTime);
    } else {
        $lang = json_decode($lang, true);
    }

    return $code == 'all' ? $lang : $lang[$code];
}
