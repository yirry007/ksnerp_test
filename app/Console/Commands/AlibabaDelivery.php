<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMore;
use App\Models\Process;
use App\Models\SupplierInfo;
use App\Models\User;
use App\Services\Agent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlibabaDelivery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alibaba:delivery';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically get alibaba delivery info';

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
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        set_time_limit(0);

        /** 获取服务器私有 IP 并验证 */
        $privateIP = getServerPrivateIP();
//        $privateIP = '172.26.13.132';
        if (!isValidIP($privateIP)) {
            Log::info('[schedule:run alibaba:delivery] invalid ip address ".' . $privateIP . '"');
            return false;
        }

        /** 根据服务器私有 IP 获取管理员用户 */
        $users = User::where('private_ip', $privateIP)->get();
        if (!count($users)) {
            Log::info('[schedule:run alibaba:delivery] can not find user data.');
            return false;
        }

        foreach ($users as $user) {
            $process = Process::where(['user_id'=>$user->id, 'type'=>'3'])->first();
            /** 跳过更新订单中的用户 */
            if ($process && $process->state) continue;

            $processId = date('YmdHis') . '-' . strtoupper(uniqid());

            if (!$process) {
                $process = Process::create([
                    'user_id'=>$user->id,
                    'type'=>'3',
                    'process_id'=>$processId,
                    'state'=>'1',
                    'create_time'=>date('Y-m-d H:i:s')
                ]);
            } else {
                $res = Process::where('id', $process->id)->update([
                    'process_id'=>$processId,
                    'state'=>'1'
                ]);

                if ($res === false) continue;
            }

            /** 获取所有属于当前管理员的店铺id */
            $shop = DB::table('shops')->select(DB::raw('GROUP_CONCAT(id) AS shop_ids'))->where('user_id', $user->id)->first();
            if (!$shop->shop_ids) {
                Log::info('[schedule:run alibaba:delivery] can not find shop data; user_id: ' . $user->id);
                $this->stopUserProcess($process->id);
                continue;
            }
            $shopIds = explode(',', $shop->shop_ids);

            /** 获取需要更新的订单商品 */
            $orderItems = OrderItem::with('orderItemMores')->select(['orders.order_id', 'order_items.id', 'order_items.sh_shop_id', 'order_items.item_id', 'order_items.item_options', 'order_items.supply_code', 'order_items.supply_options', 'order_items.supply_order_id'])
                ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereIn('order_items.shop_id', $shopIds)
                ->where([
                    ['orders.order_status', '2'],
                    ['order_items.supply_market', '1688'],
                    ['order_items.supply_order_id', '!=', ''],
                    ['order_items.supply_delivery_number', ''],
                ])
                ->get();

            if (!count($orderItems)) {
                Log::info('[schedule:run alibaba:delivery] can not find order data; user_id: ' . $user->id);
                $this->stopUserProcess($process->id);
                continue;
            }

            $supplier = SupplierInfo::where(['market'=>'Alibaba', 'user_id'=>$user->id])->first();

            foreach ($orderItems as $v) {
                /** 先处理附加采购商品 */
                if ($v->order_item_mores) {
                    foreach ($v->order_item_mores as $v1) {
                        /** 获取配送信息 */
                        try {
                            $resultMore = Agent::Supplier($supplier)->setClass('Trade')->getOrder($v1->supply_order_id);
                        } catch (\Exception $e) {
                            $this->processError($process->id, $e, $v1->supply_order_id);
                            sleep(1);
                            continue;
                        }

                        if ($resultMore['code']) {
                            sleep(1);
                            continue;
                        }

                        $supplyMoreDeliveryNumber = $result['result']['nativeLogistics']['logisticsItems'][0]['logisticsBillNo'] ?? '';
                        if (!$supplyMoreDeliveryNumber) {
                            sleep(1);
                            continue;
                        }

                        $updateResultMore = OrderItemMore::where('id', $v1->id)->update([
                            'supply_delivery_code'=>$resultMore['result']['nativeLogistics']['logisticsItems'][0]['logisticsCompanyNo'] ?? '',
                            'supply_delivery_name'=>$resultMore['result']['nativeLogistics']['logisticsItems'][0]['logisticsCompanyName'] ?? '',
                            'supply_delivery_number'=>$supplyMoreDeliveryNumber,
                        ]);

                        if ($updateResultMore === false) {
                            Log::info('[schedule:run alibaba:delivery] 1688_order_id:' . $v->supply_order_id . '[more] delivery info update failed.');
                        } else {
                            Log::info('[schedule:run alibaba:delivery] 1688_order_id:' . $v->supply_order_id . '[more] order update success.');
                        }
                    }
                }

                /** 获取配送信息 */
                try {
                    $result = Agent::Supplier($supplier)->setClass('Trade')->getOrder($v->supply_order_id);
                } catch (\Exception $e) {
                    $this->processError($process->id, $e, $v->supply_order_id);
                    sleep(1);
                    continue;
                }

                if ($result['code']) {
                    sleep(1);
                    continue;
                }

                $supplyDeliveryNumber = $result['result']['nativeLogistics']['logisticsItems'][0]['logisticsBillNo'] ?? '';
                if (!$supplyDeliveryNumber) {
                    sleep(1);
                    continue;
                }

                $updateResult = OrderItem::where('id', $v->id)->update([
                    'supply_delivery_code'=>$result['result']['nativeLogistics']['logisticsItems'][0]['logisticsCompanyNo'] ?? '',
                    'supply_delivery_name'=>$result['result']['nativeLogistics']['logisticsItems'][0]['logisticsCompanyName'] ?? '',
                    'supply_delivery_number'=>$supplyDeliveryNumber,
                ]);

                if ($updateResult === false) {
                    Log::info('[schedule:run alibaba:delivery] 1688_order_id:' . $v->supply_order_id . ' delivery info update failed.');
                } else {
                    Log::info('[schedule:run alibaba:delivery] 1688_order_id:' . $v->supply_order_id . ' order update success.');

                    /** 查看该订单的其他商品，都采购完毕（包含库存商品和自动处理商品）则自动把订单状态修改为等待出库 */
                    $sourcingFinish = true;
                    $orderItemsInSameOrder = OrderItem::with('orderItemMores')->select(['is_depot', 'auto_complete', 'supply_delivery_number'])->where('order_id', $v->order_id)->get();
                    foreach ($orderItemsInSameOrder as $_v) {
                        /** 主商品不是库存商品或官方仓商品，也没有输入快递单号，该订单不应该更新状态 */
                        if (
                            !$_v->is_depot
                            && !$_v->auto_complete
                            && !$_v->supply_delivery_number
                        ) {
                            $sourcingFinish = false;
                            break;
                        }

                        /** 主商品已有快递单号，继续查看附加采购商品是否有快递单号 */
                        if ($_v->supply_delivery_number && $_v->order_item_mores) {
                            foreach ($_v->order_item_mores as $_v1) {
                                if (!$_v1->supply_delivery_number) {
                                    $sourcingFinish = false;
                                    break;
                                }
                            }
                        }
                    }
                    if ($sourcingFinish) {
                        Order::where('order_id', $v->order_id)->update(['order_status'=>'3']);
                        Log::info('[schedule:run alibaba:delivery] order_id:' . $v->order_id . ' order status has changed to "等待出库".');
                    }
                }

                sleep(5);
            }

            Log::info('[schedule:run alibaba:delivery] 1688 delivery check finished user_id:' . $user->id);

            $this->stopUserProcess($process->id);
        }

        return true;
    }

    /**
     * 结束订单配送并更新的进程
     * @param $process_id
     */
    private function stopUserProcess($process_id)
    {
        Process::where('id', $process_id)->update([
            'process_id'=>'',
            'state'=>'0',
            'update_time'=>date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 记录后台进程的错误日志
     * @param $process_id
     * @param $e
     * @param $msg
     */
    private function processError($process_id, $e, $msg='')
    {
        $errorMessage = [
            'file'=>$e->getFile(),
            'line'=>$e->getLine(),
            'message'=>$e->getMessage() . '|custom message:' . $msg
        ];

        DB::table('process_errors')->insert([
            'process_id'=>$process_id,
            'message'=>json_encode($errorMessage),
            'create_time'=>date('Y-m-d H:i:s'),
        ]);
    }
}
