<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreGoodsItem extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;

    public function storeGoods()
    {
        return $this->belongsTo(StoreGoods::class);
    }

    /**
     * 以 supply_opt 为 key，获取商品的库存总量和即将要出库的数量
     * @param $orderItems
     * @return array
     */
    public static function getByOrderItem($orderItems)
    {
        $storeInfoBySupplyOpt = array();

        foreach ($orderItems as $v) {
            if (!$v->supply_opt) continue;

            /** 获取当前库存总量 */
            $storeNum = self::select(['num'])->where('sku', $v->supply_opt)->first();

            /** 获取即将要出库的商品数量 */
            $storeOuting = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                ->where([
                    ['is_depot', '1'],
                    ['order_items.supply_opt', $v->supply_opt],
                    ['orders.order_status', '3'],
                    ['order_items.store_statu' , '<=', '1']
                ])
                ->whereIn('order_items.shop_id', Shop::getUserShop())
                ->groupBy('order_items.supply_opt')
                ->sum('quantity');

            $storeInfoBySupplyOpt[$v->supply_opt] = [
                'store'=>$storeNum ? $storeNum->num : 0,
                'outing'=>$storeOuting,
            ];
        }

        return $storeInfoBySupplyOpt;
    }
}
