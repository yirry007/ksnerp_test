<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemMore extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
