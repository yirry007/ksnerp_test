<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailTemplateController extends Controller
{
    /**
     * 获取邮件模板列表
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $return = array();
        $req = $request->only('market', 'title');

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $templates = EmailTemplate::where('user_id', $user_id)->where(function($query) use($req){
            /** 按条件筛选店铺 */
            if (array_key_exists('market', $req)) {
                $query->where('market', $req['market']);
            }
            if ($title = array_val('title', $req)) {
                $query->whereRaw("locate('{$title}', `title`) > 0");
            }
        })->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $templates;

        return response()->json($return);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * 新增邮件模板
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $return = array();

        $req = $request->all();

        if (!array_val('market', $req)) {
            $return['code'] = 'E100041';
            $return['message'] = __('lang.Please select shop type.');
            return response()->json($return);
        }
        if (!array_key_exists('type', $req)) {
            $return['code'] = 'E100042';
            $return['message'] = __('lang.Please select template type.');
            return response()->json($return);
        }
        if (!array_val('title', $req)) {
            $return['code'] = 'E100043';
            $return['message'] = __('lang.Please input template title.');
            return response()->json($return);
        }

        /** 模板所属的管理员id */
        if (!array_key_exists('user_id', $req) || !$req['user_id']) {
            $payload = getTokenPayload();
            $req['user_id'] = $payload->aud ?: $payload->jti;
        }
        $req['create_time'] = date('Y-m-d H:i:s');
        $res = EmailTemplate::create($req);

        if ($res) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = $res;
        } else {
            $return['code'] = 'E302041';
            $return['message'] = __('lang.Data create failed.');
        }

        return response()->json($return);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * 更新邮件模板
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $return = array();

        $req = $request->except('url');

        if (!array_val('market', $req)) {
            $return['code'] = 'E100041';
            $return['message'] = __('lang.Please select shop type.');
            return response()->json($return);
        }
        if (!array_key_exists('type', $req)) {
            $return['code'] = 'E100042';
            $return['message'] = __('lang.Please select template type.');
            return response()->json($return);
        }
        if (!array_val('title', $req)) {
            $return['code'] = 'E100043';
            $return['message'] = __('lang.Please input template title.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $res = EmailTemplate::where(['id'=>$id, 'user_id'=>$user_id])->update($req);

        if ($res === false) {
            $return['code'] = 'E303041';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 删除邮件模板
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;
        $res = EmailTemplate::where(['id'=>$id, 'user_id'=>$user_id])->delete();

        if ($res === false) {
            $return['code'] = 'E304041';
            $return['message'] = __('lang.Data delete failed.');
            return response()->json($return);
        }

        /** 删除与店铺绑定的邮件模板 */
        DB::table('shop_email_template')->where('email_template_id', $id)->delete();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 获取邮件模板列表
     * @param $market
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEmailTemplates($market)
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $templates = EmailTemplate::select(['id', 'title'])->where(['user_id'=>$user_id, 'market'=>$market])->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $templates;

        return response()->json($return);
    }

    /**
     * 获取店铺绑定的邮件模板id
     * @param $shop_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShopEmailTemplate($shop_id)
    {
        $return = array();

        $shop = Shop::select(['id'])->where('id', $shop_id)->first();

        if (!$shop)  {
            $return['code'] = 'E301041';
            $return['message'] = __('lang.Shop does not exists.');
            return response()->json($return);
        }

        $shopEmailTemplates = DB::table('shop_email_template')->where('shop_id', $shop->id)->orderBy('email_template_id', 'ASC')->get();

        $templates = array();
        foreach ($shopEmailTemplates as $v) {
            $templates[] = (string)$v->email_template_id;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $templates;

        return response()->json($return);
    }
}
