<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestLog extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;

    /** 日志保留天数 */
    const EXPIRE = 3;

    /** 1/100 的概率删除N天前的日志 */
    public static function randomDelNDayAgo()
    {
        if (mt_rand(0, 99) == 99) {
            /** 7天前的时间戳 */
            $ago = time() - self::EXPIRE * 86400;
            self::where('create_time', '<', date('Y-m-d 00:00:00', $ago))->delete();
        }
    }
}
