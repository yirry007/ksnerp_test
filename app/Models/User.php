<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = [
        'parent_id',
        'username',
        'password',
        'server_ip',
        'private_ip',
        'domain',
        'tag',
        'create_time',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    public $timestamps = false;
}
