<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\ExcelTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Tools\Image\Image;
use Illuminate\Http\Request;

class ExcelTemplateController extends Controller
{
    /**
     * 获取Excel模板列表
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $return = array();
        $req = $request->only('title', 'type');

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $templates = ExcelTemplate::where('user_id', $user_id)->where(function($query) use($req){
            /** 按条件筛选店铺 */
            if ($title = array_val('title', $req)) {
                $query->whereRaw("locate('{$title}', `title`) > 0");
            }
            if (array_key_exists('type', $req)) {
                $query->where('type', $req['type']);
            }
        })->get();

        $_orderFields = ExcelTemplate::exportOrderFields();
        $orderFields = array();
        foreach ($_orderFields as $v) {
            $orderFields[$v['column']] = $v['name'];
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['templates'] = $templates;
        $return['result']['order_field_map'] = $orderFields;

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
     * 新增Excel模板
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $return = array();

        $req = $request->all();

        if (!array_val('title', $req)) {
            $return['code'] = 'E100053';
            $return['message'] = __('lang.Please input template title.');
            return response()->json($return);
        }
        if (!array_key_exists('type', $req)) {
            $return['code'] = 'E100052';
            $return['message'] = __('lang.Please select template type.');
            return response()->json($return);
        }
        if (!array_key_exists('fields', $req) || !count($req['fields'])) {
            $return['code'] = 'E100054';
            $return['message'] = __('lang.Please select export fields.');
            return response()->json($return);
        }

        /** 模板所属的管理员id */
        $payload = getTokenPayload();
        $req['user_id'] = $payload->aud ?: $payload->jti;
        $req['fields'] = json_encode($req['fields']);
        $req['create_time'] = date('Y-m-d H:i:s');
        $res = ExcelTemplate::create($req);

        if ($res) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = $res;
        } else {
            $return['code'] = 'E302051';
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
     * 更新Excel模板
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $return = array();

        $req = $request->only('title', 'type', 'fields');

        if (!array_val('title', $req)) {
            $return['code'] = 'E100053';
            $return['message'] = __('lang.Please input template title.');
            return response()->json($return);
        }
        if (!array_key_exists('type', $req)) {
            $return['code'] = 'E100052';
            $return['message'] = __('lang.Please select template type.');
            return response()->json($return);
        }
        if (!array_key_exists('fields', $req) || !count($req['fields'])) {
            $return['code'] = 'E100054';
            $return['message'] = __('lang.Please select export fields.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $req['fields'] = json_encode($req['fields']);

        $res = ExcelTemplate::where(['id'=>$id, 'user_id'=>$user_id])->update($req);

        if ($res === false) {
            $return['code'] = 'E303051';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 删除Excel模板
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;
        $res = ExcelTemplate::where(['id'=>$id, 'user_id'=>$user_id])->delete();

        if ($res === false) {
            $return['code'] = 'E304051';
            $return['message'] = __('lang.Data delete failed.');
            return response()->json($return);
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 获取待输出字段列表
     * @param $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExportFields($type)
    {
        $return = array();
        $fields = array();

        if ($type == 1) {//订单 Excel 项目
            $fields = ExcelTemplate::exportOrderFields();
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $fields;

        return response()->json($return);
    }

    /**
     * 获取Excel导出数据
     * @param Request $request
     * @param $template_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExportData(Request $request, $template_id)
    {
        $return = array();

        $template = ExcelTemplate::find($template_id);

        if (!$template) {
            $return['code'] = 'E301051';
            $return['message'] = __('lang.Invalid Excel template.');
            return response()->json($return);
        }

        $req = $request->only('order_status', 'market', 'sh_shop_id', 'skip_supplied');

        if ($template->type == 1 && !array_key_exists('order_status', $req)) {
            $return['code'] = 'E100051';
            $return['message'] = __('lang.Invalid order status.');
            return response()->json($return);
        }

        $selectColumn = [];
        $exportField = [];
        $exportData = [];
        $hasImage = false;
        if ($template->type == 1) {// 订单 Excel 数据
            $fields = ExcelTemplate::exportOrderFields();
            $exportFields = json_decode($template->fields, true);

            /** 判断 Excel 有没有图片列 */
            $hasImage = in_array('item_image_path', $exportFields);

            foreach ($exportFields as $k=>$v) {
                foreach ($fields as $v1) {
                    if ($v == $v1['column']) {
                        $exportField[$k]['column'] = $v1['column'];
                        $exportField[$k]['name'] = $v1['name'];

                        $selectColumn[] = $v1['table'] . '.' . $v1['column'];
                    }
                }
            }

            $exportData = OrderItem::select($selectColumn)
                ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                ->leftJoin('shops', 'order_items.shop_id', '=', 'shops.id')
                ->whereIn('order_items.shop_id', Shop::getUserShop())
                ->where([
                    ['orders.order_status', '=', $req['order_status']],
                    ['orders.is_reserved', '=', '0'],
                    ['orders.auto_complete', '=', '0'],
                ])
                ->where(function($query) use($req){
                    if (array_key_exists('market', $req) && $req['market']) {
                        $query->where('orders.market', $req['market']);
                    }
                    if (array_key_exists('sh_shop_id', $req) && $req['sh_shop_id']) {
                        $query->where('order_items.sh_shop_id', $req['sh_shop_id']);
                    }
                    if (
                        $req['order_status'] == '2'
                        && array_key_exists('skip_supplied', $req)
                        && $req['skip_supplied']
                    ) {
                        /** 筛选已采购商品 */
                        $query->where('order_items.supply_order_id', '');
                    }
                })
                ->orderBy('orders.order_time', 'DESC')
                ->get();
        }

        if (!count($exportData)) {
            $return['code'] = 'E301052';
            $return['message'] = __('lang.No data.');
            return response()->json($return);
        }

        if ($hasImage) {
            /** 获取的数据中图片地址转为 base64 字符串 */
            $_exportData = array();

            foreach ($exportData as $v) {
                if (!$v->item_image_path) {
                    $_exportData[] = $v;
                    continue;
                }

                try {
                    $v->item_image_path = Image::readAsBase64($v->item_image_path);
                } catch (\Exception $e) {
                    $v->item_image_path = '';
                }

                $_exportData[] = $v;
            }

            $exportData = $_exportData;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['export_field'] = $exportField;
        $return['result']['export_data'] = $exportData;

        return response()->json($return);
    }

    /**
     * 根据 Excel 的数据更新订单与订单商品
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function importExcel(Request $request)
    {
        $return = array();

        $req = $request->only('type', 'data', 'option');
        $type = array_val('type', $req);

        if (!$type) {
            $return['code'] = 'E100051';
            $return['message'] = __('lang.Invalid type.');
            return response()->json($return);
        }

        if (!array_key_exists('data', $req) || !is_array($req['data'])) {
            $return['code'] = 'E100052';
            $return['message'] = __('lang.Invalid data.');
            return response()->json($return);
        }

        $importData = $req['data'];
        $updateResult = [];

        if ($type == 1) {// 处理订单 Excel
            if (
                !array_key_exists('option', $req)
                || !array_key_exists('order_status', $req['option'])
            ) {
                $return['code'] = 'E100053';
                $return['message'] = __('lang.Invalid order status.');
                return response()->json($return);
            }

            $orderStatus = $req['option']['order_status'];

            $orderFieldMap = array();
            $orderFields = ExcelTemplate::exportOrderFields();

            foreach ($orderFields as $v) {
                $orderFieldMap[$v['name']] = $v;
            }

            foreach ($importData as $k=>$v) {
                $orderId = $v['订单ID'];
                $itemId = $v['订单商品ID'];
                $_updateData = array();
                $_result = array();

                foreach ($v as $field=>$value) {
                    /** 没有找到字段，则跳过 */
                    if (!array_key_exists($field, $orderFieldMap)) continue;

                    /** 字段映射表中 update 值为 0（不更新字段）的跳过 */
                    if (!$orderFieldMap[$field]['update']) continue;

                    /** 字段映射表中 table 值为 orders（订单表）的跳过 */
                    if ($orderFieldMap[$field]['table'] == 'orders') continue;

                    /** value 值为 null 或者空字符串，则跳过 */
                    if (is_null($value) || $value == '') continue;

                    $_fieldKey = $orderFieldMap[$field]['table'] . '.' . $orderFieldMap[$field]['column'];
                    $_updateData[$_fieldKey] = $value;
                }

                /** 没有可更新项目（$_updateData 为 []），则跳过 */
                if (!count($_updateData)) {
                    $_result['code'] = 'E201051';
                    $_result['message'] = __('lang.No update data.');
                    $_result['order_id'] = $orderId;
                    $_result['item_id'] = $itemId;

                    $updateResult[] = $_result;
                    continue;
                }

                $res = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')->where(['order_items.item_id'=>$itemId, 'orders.order_id'=>$orderId, 'orders.order_status'=>$orderStatus])->update($_updateData);

                if ($res === false) {
                    $_result['code'] = 'E303059';
                    $_result['message'] = __('lang.Data update failed.');
                } else {
                    $_result['code'] = '';
                    $_result['message'] = 'SUCCESS';

                    /** 更新订单表 ksn_orders 表中的国际物流信息 */
                    $shippingData = array();
                    if (
                        /** 假如设置了国际物流默认值为 1 */
                        array_key_exists('order_items.shipping_default', $_updateData)
                        && $_updateData['order_items.shipping_default'] == 1
                    ) {
                        $shippingData['shipping_company'] = $_updateData['order_items.shipping_company'] ?? '';
                        $shippingData['invoice_number_1'] = $_updateData['order_items.invoice_number'] ?? '';
                    }

                    /** 假如国际物流公司和运单号均已被设置且不为空，则更新 ksn_orders */
                    if (
                        array_key_exists('shipping_company', $shippingData)
                        && array_key_exists('invoice_number_1', $shippingData)
                        && $shippingData['shipping_company']
                        && $shippingData['invoice_number_1']
                    ) {
                        Order::where(['order_id'=>$orderId, 'order_status'=>$orderStatus])->update($shippingData);
                    }
                }

                $_result['order_id'] = $orderId;
                $_result['item_id'] = $itemId;

                $updateResult[] = $_result;
            }
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $updateResult;

        return response()->json($return);
    }
}
