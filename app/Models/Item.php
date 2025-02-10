<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $hidden = ['user_id', 'item_sub_id', 'item_management_id', 'item_number'];
}
