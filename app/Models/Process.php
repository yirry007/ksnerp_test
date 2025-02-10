<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Process extends Model
{
    protected $fillable = ['user_id', 'type', 'process_id', 'state', 'create_time'];
    public $timestamps = false;
}
