<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Agent;

class TestController extends Controller
{
    public function testGet()
    {
        return 'test get.';
    }

    public function testPost()
    {
        return 'test post.';
    }

    public function apiTestGet()
    {
        $shop = Shop::find(113);
        $res = Agent::Markets($shop)->setClass('Order')->getOrder('1007340983');
        dd($res);
    }

    public function apiTestPost()
    {
        return 'api test post.';
    }
}
