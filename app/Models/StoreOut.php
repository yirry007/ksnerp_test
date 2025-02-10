<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreOut extends Model
{
    protected $table = 'store_out';
    protected $guarded = ['id'];
    public $timestamps = false;

    /**
     * 库存商品中添加SKU
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function storeOutLog()
    {
        return $this->hasMany('App\Models\StoreOutLog');
    }
}
