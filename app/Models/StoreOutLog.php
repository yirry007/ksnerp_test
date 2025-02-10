<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreOutLog extends Model
{
    protected $table = 'store_out_log';
    protected $guarded = ['id'];
    public $timestamps = false;

    public function storeOut()
    {
        return $this->belongsTo(StoreOut::class);
    }
}
