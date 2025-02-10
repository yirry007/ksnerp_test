<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;
use YConnect\Constant\OIDConnectDisplay;
use YConnect\Constant\OIDConnectPrompt;
use YConnect\Constant\OIDConnectScope;
use YConnect\Constant\ResponseType;
use YConnect\Credential\ClientCredential;
use YConnect\YConnectClient;

class YahooController extends Controller
{
    const STATE = '0bce015f3de97a543fbdefb2ac48fb62';
    const NONCE = 'abed3511144106f23b87df401f246d81';
    const PLAIN_CODE_CHALLENGE = "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM._~";

    /**
     * 打开 yahoo 店铺授权页面
     * @param Request $request
     * @param $shop_id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function auth(Request $request, $shop_id)
    {
        $shop = Shop::where('shop_id', $shop_id)->first();

        if (!$shop) {
            $msg = __('lang.Invalid shop info.');
            return view('yahoo.auth', compact('msg'));
        }
        if (!$shop->app_key) {
            $msg = __('lang.Parameter error.') . 'APP_KEY';
            return view('yahoo.auth', compact('msg'));
        }
        if (!$shop->app_secret) {
            $msg = __('lang.Parameter error.') . 'APP_SECRET';
            return view('yahoo.auth', compact('msg'));
        }
        if (!$shop->redirect_url) {
            $msg = __('lang.Parameter error.') . 'redirect url';
            return view('yahoo.auth', compact('msg'));
        }

        $responseType = ResponseType::CODE;
        $scope = array(
            OIDConnectScope::OPENID,
            OIDConnectScope::PROFILE,
            OIDConnectScope::EMAIL,
            OIDConnectScope::ADDRESS
        );
        $display = OIDConnectDisplay::DEFAULT_DISPLAY;
        $prompt = array(
            OIDConnectPrompt::DEFAULT_PROMPT
        );

        // クレデンシャルインスタンス生成
        $cred = new ClientCredential($shop->app_key, $shop->app_secret);
        // YConnectクライアントインスタンス生成
        $client = new YConnectClient($cred);

        // デバッグ用ログ出力
        $client->enableDebugMode();

        $client->requestAuth(
            $shop->redirect_url,
            self::STATE,
            self::NONCE,
            $responseType,
            $scope,
            $display,
            $prompt,
            3600,
            self::PLAIN_CODE_CHALLENGE
        );
    }

    /**
     * yahoo 店铺授权操作后，重定向的页面
     * @param Request $request
     * @param $shop_id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     * @throws \YConnect\Exception\TokenException
     */
    public function redirect(Request $request, $shop_id)
    {
        $shop = Shop::where('shop_id', $shop_id)->first();

        if (!$shop) {
            $msg = __('lang.Invalid shop info.');
            return view('yahoo.auth', compact('msg'));
        }

        // クレデンシャルインスタンス生成
        $cred = new ClientCredential($shop->app_key, $shop->app_secret);
        // YConnectクライアントインスタンス生成
        $client = new YConnectClient($cred);
        // Authorization Codeを取得
        $code = $client->getAuthorizationCode(self::STATE);

        // 追加 api 请求头信息
        $headers = [
            "X-sws-signature: " . getYahooEncAuthVal($shop),
            "X-sws-signature-version: 5"
        ];
        $client->setExtraHeaders($headers);

        // 添加代理服务器信息
//        $proxy = $shop->proxy_ip;
//        if ($shop->proxy_on && $proxy) {
//            $proxyInfo = getProxyInfo($proxy);
//            count($proxyInfo) > 0 && $client->setCurlOption(['proxy'=>$proxyInfo]);
//        }

        try {
            $client->requestAccessToken(
                $shop->redirect_url,
                $code,
                self::PLAIN_CODE_CHALLENGE
            );

            $res = Shop::where('id', $shop->id)->update([
                'token'=>$client->getAccessToken(),
                'refresh_token'=>$client->getRefreshToken()
            ]);

            if (!$res) {
                $msg = 'access_token update failed';
            } else {
                $msg = 'access_token update success';
            }
        } catch (\Exception $e) {
            $msg = 'access_token request failed';
        }

        return view('yahoo.auth', compact('msg'));
    }
}
