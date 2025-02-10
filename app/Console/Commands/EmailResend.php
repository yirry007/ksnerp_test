<?php

namespace App\Console\Commands;

use App\Models\EmailSendLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmailResend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:resend';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resend today`s failed email';

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
     * 重新发送发送失败的邮件
     * @return bool
     */
    public function handle()
    {
        set_time_limit(0);

        /** 获取服务器私有 IP 并验证 */
        $privateIP = getServerPrivateIP();
        if (!isValidIP($privateIP)) {
            Log::info('[schedule:run email:resend] invalid ip address ".' . $privateIP . '"');
            return false;
        }

        /** 根据服务器私有 IP 获取管理员用户 */
        $emailLogData = User::select(DB::raw('GROUP_CONCAT(id) as user_ids'))->where('private_ip', $privateIP)->first();
        if (!$emailLogData->user_ids) {
            Log::info('[schedule:run email:resend] can not find user data.');
            return false;
        }

        $userIdArr = explode(',', $emailLogData->user_ids);

        /** 获取 12 小时内发送失败的邮件日志 */
        $before12hours = time() - 3600 * 12;
        $sendFailedEmailLogs = EmailSendLog::whereIn('user_id', $userIdArr)->where('is_success', '0')->where('create_time', '>', date('Y-m-d H:i:s', $before12hours))->get();

        if (!count($sendFailedEmailLogs)) {
            Log::info('[schedule:run email:resend] can not find send failed email logs');
            return false;
        }

        foreach ($sendFailedEmailLogs as $v) {
            /** 根据发送失败的邮件的 order_id 可以查询出发送成功的邮件发送日志，则跳过该循环 */
            $hasSendSuccessEmail = EmailSendLog::where(['order_id'=>$v->order_id, 'template_type'=>$v->template_type, 'is_success'=>'1'])->count();
            if ($hasSendSuccessEmail) continue;

            $hostPort = explode(':', $v->smtp_address);

            $config = [
                // 服务器配置
                'encryption'=>strpos(strtolower($v->smtp_address), 'rakuten') !== false ? '' : 'ssl',
                'host'=>$hostPort[0],
                'port'=>$hostPort[1],
                'username'=>$v->smtp_username,
                'password'=>$v->smtp_password,

                // 模板，收件人，发件人配置
                'to'=>$v->to,
                'receiver'=>$v->receiver,
                'from'=>$v->smtp_email,
                'sender'=>$v->sender,
                'subject'=>$v->subject
            ];

            $updateData = array();
            $updateData['attempted'] = $v->attempted + 1;
            $updateData['update_time'] = date('Y-m-d H:i:s');

            try {
                sendEmail($config, $v->content);
                $updateData['is_success'] = '1';
                $updateData['errors'] = '';

                Log::info('[schedule:run email:resend] resend email successfully, id:' . $v->id);
            } catch (\Exception $e) {
                $updateData['errors'] = json_encode([
                    'file'=>$e->getFile(),
                    'line'=>$e->getLine(),
                    'message'=>$e->getMessage()
                ]);
                Log::info('[schedule:run email:resend] resend email failed, id:' . $v->id);
            }

            EmailSendLog::where('id', $v->id)->update($updateData);
            sleep(10);
        }

        return true;
    }
}
