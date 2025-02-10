<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSendLog extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
