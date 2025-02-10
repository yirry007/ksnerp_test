<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ItemDiscover extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'item:discover';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get item info from market order item data';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return boolean
     */
    public function handle()
    {
        set_time_limit(0);

        /** 获取服务器私有 IP 并验证 */
        $privateIP = getServerPrivateIP();
//        $privateIP = '172.26.13.132';
        if (!isValidIP($privateIP)) {
            Log::info('[item:discover] invalid ip address ".' . $privateIP . '"');
            return false;
        }

        /** 根据服务器私有 IP 获取管理员用户 */
        $users = User::where('private_ip', $privateIP)->get();
        if (!count($users)) {
            Log::info('[item:discover] can not find user data.');
            return false;
        }

        foreach ($users as $user) {
            $discoveredNum = 0;
            $maxSameItemNum = 50;
            $sameItemNum = 0;
            $hasNext = true;
            $idMax = PHP_INT_MAX;

            while ($sameItemNum < $maxSameItemNum && $hasNext) {
                /** 在 order_items 数据表中获取 id 值小于 $idMax 的两个数据 */
                $orderItems = OrderItem::select(['order_items.id', 'order_items.item_id', 'order_items.item_sub_id', 'order_items.item_management_id', 'order_items.item_number', 'order_items.item_name', 'order_items.item_price', 'order_items.item_url', 'order_items.item_options', 'shops.market'])->leftJoin('shops', 'order_items.shop_id', '=', 'shops.id')->where('shops.user_id', $user->id)->where('order_items.id', '<=', $idMax)->orderBy('order_items.id', 'DESC')->limit(2)->get();

                $currentOrderItem = $orderItems[0] ?? null;//当前操作的订单商品
                $nextOrderItem = $orderItems[1] ?? null;//下一个操作的商品

                $hasNext = $nextOrderItem ? true : false;//查看是否有下一个商品
                $idMax = $nextOrderItem ? $nextOrderItem->id : 0;//下一个商品id作为查询的最大id值

                /** 没有获取到任何数据，则直接结束循环 */
                if (!$currentOrderItem) break;

                /** 商品资料中查看有没有一样的商品 */
                $exist = Item::where(['user_id'=>$user->id, 'item_id'=>$currentOrderItem->item_id, 'item_options'=>$currentOrderItem->item_options])->count();

                if ($exist) {
                    /** 当前操作的商品数据库中存在 */
                    $sameItemNum++;//相同商品个数 +1，连续达到一定数量则终止循环
                    continue;
                }

                $sameItemNum = 0;//重置相同商品个数

                $insert = array();
                $insert['user_id'] = $user->id;
                $insert['item_id'] = $currentOrderItem->item_id;
                $insert['item_sub_id'] = $currentOrderItem->item_sub_id;
                $insert['item_management_id'] = $currentOrderItem->item_management_id;
                $insert['item_number'] = $currentOrderItem->item_number;
                $insert['ksn_code'] = strtoupper(uniqid('KSN_'));
                $insert['market'] = $currentOrderItem->market;
                $insert['item_name'] = $currentOrderItem->item_name;
                $insert['item_price'] = $currentOrderItem->item_price;
                $insert['item_url'] = $currentOrderItem->item_url;
                $insert['item_options'] = $currentOrderItem->item_options;
                $insert['create_time'] = date('Y-m-d H:i:s');

                $res = Item::create($insert);
                if (!$res) continue;

                $discoveredNum++;
            }

            Log::info('[item:discover] user ' . $user->id . ' has discovered ' . $discoveredNum . ' items.');
        }

        return true;
    }
}
