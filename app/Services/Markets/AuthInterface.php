<?php


namespace App\Services\Markets;


interface AuthInterface
{
    /**
     * 获取店铺 token
     * @return array
     */
    public function getToken();

    /**
     * 刷新店铺 token
     * @return array
     */
    public function refreshToken();

    /**
     * 验证 token 是否有效
     * @return array
     */
    public function checkConnection();
}
