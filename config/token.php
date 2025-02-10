<?php

return [
    /** 关于 jwt 生成 token 时间的配置 */
    'jwt_alg' => 'HS256',//token 加密解密算法
    'api_key' => 'KSnERPkSNERp',//token key
    'access_token_lifetime' => 3600 * 4,//access_token 有效期
    'refresh_token_lifetime' => 86400 * 28,//refresh_token 有效期
];
