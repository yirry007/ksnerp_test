<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreGoods extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;

    /**
     * 库存商品中添加SKU
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function storeGoodsItems()
    {
        return $this->hasMany('App\Models\StoreGoodsItem');
    }
}
