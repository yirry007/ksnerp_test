<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Process;
use App\Models\User;
use App\Services\Agent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderShipped extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:shipped';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update order status to shipped after get delivery info';

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
     * @return boolean
     */
    public function handle()
    {
        set_time_limit(0);

        /** 获取服务器私有 IP 并验证 */
        $privateIP = getServerPrivateIP();
        if (!isValidIP($privateIP)) {
            Log::info('[schedule:run order:shipped] invalid ip address ".' . $privateIP . '"');
            return false;
        }

        /** 根据服务器私有 IP 获取管理员用户 */
        $users = User::where('private_ip', $privateIP)->get();
        if (!count($users)) {
            Log::info('[schedule:run order:shipped] can not find user data.');
            return false;
        }

        foreach ($users as $user) {
            $process = Process::where(['user_id'=>$user->id, 'type'=>'2'])->first();
            /** 跳过更新订单中的用户 */
            if ($process && $process->state) continue;

            $processId = date('YmdHis') . '-' . strtoupper(uniqid());

            if (!$process) {
                $process = Process::create([
                    'user_id'=>$user->id,
                    'type'=>'2',
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

            /** 获取所有属于当前管理员的店铺 */
            $shops = DB::table('shops')->select(['id', 'shop_id'])->where('user_id', $user->id)->get();
            if (!count($shops)) {
                Log::info('[schedule:run order:shipped] can not find shop data; user_id: ' . $user->id);
                $this->stopUserProcess($process->id);
                continue;
            }

            $shopIds = array();
            $shopEmailTemplateMap = array();//店铺和邮件模板映射表
            foreach ($shops as $v) {
                $shopIds[] = $v->id;

                /**
                 *   店铺和邮件模板映射表数据格式
                 *   [
                 *       'nahanaha66<shop_id>'=>'5<template_id>',
                 *       '69340455<shop_id>'=>'1<template_id>',
                 *       'delinwoke<shop_id>'=>'8<template_id>',
                 *   ]
                 */
                $shopEmailTemplate = DB::table('shop_email_template')
                    ->leftJoin('email_templates', 'shop_email_template.email_template_id', '=', 'email_templates.id')
                    ->where(['shop_email_template.shop_id'=>$v->id, 'email_templates.type'=>'4'])
                    ->first();
                $shopEmailTemplateMap[(string)$v->shop_id] = $shopEmailTemplate ? $shopEmailTemplate->email_template_id : 0;
            }

            /** 获取需要更新的订单 */
            $orders = Order::select(['id', 'shop_id', 'sh_shop_id', 'order_id', 'order_status', 'shipping_company', 'invoice_number_1', 'invoice_number_2'])->whereIn('shop_id', $shopIds)->where('order_status', '4')->get();

            if (!count($orders)) {
                Log::info('[schedule:run order:shipped] can not find order data; user_id: ' . $user->id);
                $this->stopUserProcess($process->id);
                continue;
            }

            foreach ($orders as $v) {
                if (!$v->shipping_company) continue;
                if (!$v->invoice_number_1 && !$v->invoice_number_2) continue;

                $module = shippingCompanyMap($v->shipping_company);

                if (!$module) {
                    Log::info('[schedule:run order:shipped] order_id:' . $v->order_id . ' invalid shipping_company.');
                    continue;
                }

                $invoiceNumber = $v->invoice_number_1 ?: $v->invoice_number_2;

                /** 获取配送信息 */
                try {
                    $deliveryResult = Agent::Delivery($module)->setClass('Crawl')->deliveryInfo($invoiceNumber);
                } catch (\Exception $e) {
                    $this->processError($process->id, $e, $v->order_id);
                    sleep(2);
                    continue;
                }

                if ($deliveryResult['code']) {
                    sleep(2);
                    continue;
                }

                $shippingData = array();
                $shippingData['shipping_company'] = $v->shipping_company;
                $shippingData['invoice_number_1'] = $v->invoice_number_1;
                $shippingData['invoice_number_2'] = $v->invoice_number_2;
                $shippingData['shipping_date'] = $deliveryResult['result']['shipping_date'] ?? date('Y-m-d');

                /** 在各平台和本地更新订单信息 */
                try {
                    $updateResult = Order::updateOrderStatusInMarket($v->order_id, $v->order_status, $shippingData, $shopEmailTemplateMap);
                } catch (\Exception $e) {
                    $this->processError($process->id, $e, $v->order_id);
                    sleep(1);
                    continue;
                }

                if ($updateResult['code']) {
                    Log::info('[schedule:run order:shipped] order_id:' . $v->order_id . ' order update failed. ' . $updateResult['message']);
                } else {
                    Log::info('[schedule:run order:shipped] order_id:' . $v->order_id . ' order update success.');
                }

                sleep(10);
            }

            Log::info('[schedule:run order:shipped] Order delivery check finished user_id:' . $user->id);

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
