<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Process;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:update {user_id} {day_ago=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Request order api from markets and update database';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 后台请求订单api，并更新数据
     * Execute the console command.
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handle()
    {
        set_time_limit(0);

        $arg = $this->arguments();

        $userId = $arg['user_id'];//必须传递管理员用户id（普通用户id查询不到店铺）
        $dayAgo = $arg['day_ago'];//N天前
        $dayMax = 30;

        $process = Process::where(['user_id'=>$userId, 'type'=>'1'])->first();

        /** 进程正在执行，则直接返回false */
        if ($process && $process->state) return false;

        $processId = date('YmdHis') . '-' . strtoupper(uniqid());

        if (!$process) {
            $process = Process::create([
                'user_id'=>$userId,
                'type'=>'1',
                'process_id'=>$processId,
                'state'=>'1',
                'create_time'=>date('Y-m-d H:i:s')
            ]);
        } else {
            $res = Process::where('id', $process->id)->update([
                'process_id'=>$processId,
                'state'=>'1'
            ]);

            if ($res === false) return false;
        }

        $shops = Shop::where('user_id', $userId)->get();
        $nonUpdateCount = array();//订单不更新次数
        $nonUpdateCountMax = 10;//订单不更新次数上限

        while ($dayAgo <= $dayMax) {//循环日期（今天，昨天，前天...）
            foreach ($shops as $k=>$shop) {//循环店铺（Rakuten, Wowma, Qoo10...）
                if (!$shop->token) {
                    /** 没有 token 的店铺直接剔除掉 */
                    unset($shops[$k]);
                    continue;
                }

                try {
                    $orders = Order::getOrdersFromApi($shop, $dayAgo);
                } catch (\Exception $e) {
                    $this->processError($process->id, $e);
                    continue;
                }

                if ($orders['code']) {
                    /** api请求失败的店铺剔除掉 */
                    unset($shops[$k]);
                    continue;
                }

                /** 假如没有订单数据，直接continue */
                if (!array_key_exists('result', $orders) || !$orders['result'] || !count($orders['result'])) continue;

                /** 更新数据库中的订单数据 */
                try {
                    $updateCount = Order::updateOrders($shop, $orders['result']);
                } catch (\Exception $e) {
                    $this->processError($process->id, $e);
                    continue;
                }

                if (!$updateCount) {
                    /** 有数据但没有被更新，则订单不更新次数 +1（连续不更新才能累加） */
                    $_count = array_key_exists($shop->id, $nonUpdateCount) ? $nonUpdateCount[$shop->id] : 0;
                    $nonUpdateCount[$shop->id] = $_count + 1;
                } else {
                    /** 订单数据更新成功，则该店铺的订单不更新次数清零 */
                    $nonUpdateCount[$shop->id] = 0;
                }

                if ($nonUpdateCount[$shop->id] >= $nonUpdateCountMax) {
                    /** 连续N天有订单数据，但没有更新数据库，则剔除该店铺 */
                    unset($shops[$k]);
                }
            }

            if (!count($shops)) break;

            $dayAgo++;
        }

        Log::info('[order:update] Order update finished; user_id: ' . $userId . ' ;period: ' . $dayAgo);

        /** 结束订单拉取进程（仅仅是数据库中的标记，state=0） */
        Process::where('id', $process->id)->update([
            'process_id'=>'',
            'state'=>'0',
            'update_time'=>date('Y-m-d H:i:s')
        ]);

        return true;
    }

    /**
     * 记录后台进程的错误日志
     * @param $process_id
     * @param $e
     */
    private function processError($process_id, $e)
    {
        $errorMessage = [
            'file'=>$e->getFile(),
            'line'=>$e->getLine(),
            'message'=>$e->getMessage()
        ];

        DB::table('process_errors')->insert([
            'process_id'=>$process_id,
            'message'=>json_encode($errorMessage),
            'create_time'=>date('Y-m-d H:i:s'),
        ]);
    }
}
