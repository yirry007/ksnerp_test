<?php


namespace App\Services\Supplier;


interface AuthInterface
{
    /**
     * 获取平台的 access_token
     * @return array
     */
    function getToken();

    /**
     * 通过 refresh_token 刷新平台的 access_token
     * @return array
     */
    function refreshToken();
}
