<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierInfo;
use App\Services\Agent;
use App\Tools\Image\Image;
use Illuminate\Http\Request;

class ImageSearchController extends Controller
{
    public function imageSave(Request $request)
    {
        $return = array();

        $req = $request->only('image_url');
        $imageUrl = array_val('image_url', $req);

        if (!$imageUrl) {
            $return['code'] = 'E0071';
            $return['message'] = __('lang.Invalid image url.');
            return response()->json($return);
        }

        $filepath = Image::save($imageUrl, 'url', false);

        if (!$filepath) {
            $return['code'] = 'E0072';
            $return['message'] = __('lang.Image save failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = [
                'image_path'=>$filepath,
            ];
        }

        return response()->json($return);
    }

    public function imageUpload(Request $request)
    {
        $return = array();

        $req = $request->only('image_path');
        $imagePath = array_val('image_path', $req);

        if (!$imagePath) {
            $return['code'] = 'E0073';
            $return['message'] = __('lang.Invalid image url.');
            return response()->json($return);
        }

        $imgBase64 = Image::readAsBase64($imagePath);

        if (!$imgBase64) {
            $return['code'] = 'E0074';
            $return['message'] = __('lang.Read image failed.');
            return response()->json($return);
        }

        $base64str = explode(';base64,', $imgBase64)[1];

        $supplierId = 1;
        $supplier = SupplierInfo::find($supplierId);
        $imageId = Agent::Supplier($supplier)->setClass('Item')->getImageId($base64str);

        if ($imageId['code']) {
            $return['code'] = 'E0074';
            $return['message'] = __('lang.Analyze image failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = [
                'imageId'=>$imageId['result']
            ];
        }

        @unlink(public_path($imagePath));

        return response()->json($return);
    }

    public function imageSearch(Request $request)
    {
        $return = array();

        $req = $request->only('image_id', 'page_num', 'page_size', 'country');

        if (!$imageId = array_val('image_id', $req)) {
            $return['code'] = 'E0075';
            $return['message'] = __('lang.Invalid image_id.');
            return response()->json($return);
        }

        $pageNum = $req['page_num'] ?? 1;
        $pageSize = $req['page_size'] ?? 20;
        $country = $req['country'] ?? 'en';

        $supplierId = 1;
        $supplier = SupplierInfo::find($supplierId);

        $res = Agent::Supplier($supplier)->setClass('Item')->searchItemsByImageId($imageId, [
            'beginPage'=>$pageNum,
            'pageSize'=>$pageSize,
            'country'=>$country
        ]);

        if ($res['code']) {
            $return['code'] = $res['code'];
            $return['message'] = $res['message'];
        } else {
            $return['code'] = $res['code'];
            $return['message'] = $res['message'];
            $return['result'] = $res['result'] ?? [];
        }

        return response()->json($return);
    }
}
