<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VersionUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'version:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update ERP version automatically';

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
     * 自动更新 ERP 版本
     * @return bool
     */
    public function handle()
    {
        set_time_limit(0);

        /** 获取服务器私有 IP 并验证 */
        $privateIP = getServerPrivateIP();
        if (!isValidIP($privateIP)) {
            Log::info('[schedule:run version:update] invalid ip address ".' . $privateIP . '"');
            return false;
        }

        $currentVersion = DB::table('versions')->where('private_ip', $privateIP)->first();

        if (!$currentVersion) {
            Log::info('[schedule:run version:update] cannot find server ".' . $privateIP . '"');
            return false;
        }

        /** 后台最新版本 */
        $lastVersion = DB::table('update_logs')->select(['version', 'update_time'])->orderBy('id', 'DESC')->first();

        /** 是否需要更新系统 */
        $updatable = preg_replace('/\D/', '', $lastVersion->version) > preg_replace('/\D/', '', $currentVersion->version);

        if (!$updatable) {
            Log::info('[schedule:run version:update] already new version ".' . $privateIP . '"');
            return false;
        }

        $server_alpha = '172.26.7.111';
        $server_beta = '172.26.13.132';

        if ($privateIP == $server_alpha) {
            $branch = 'alpha';
        } elseif ($privateIP == $server_beta) {
            $branch = 'beta';
        } else {
            $branch = 'release';
        }

        $command = 'cd /var/www/html/ksnerp_seller';
        $command .= ' && sudo git reset --hard HEAD';
        $command .= ' && sudo git checkout ' . $branch;
        $command .= ' && sudo git pull';
        $command .= ' && sudo chmod -R 777 /var/www/html/ksnerp_seller';

        exec($command);

        /** 更新数据库中的用户的 ERP 版本信息 */
        DB::table('versions')->where('private_ip', $privateIP)->update([
            'version'=>$lastVersion->version,
            'update_time'=>date('Y-m-d H:i:s')
        ]);

        Log::info('[schedule:run version:update] update version successfully, version: "' . $lastVersion->version . '"');
    }
}
