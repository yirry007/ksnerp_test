<?php

namespace App\Services\Delivery\Yamato;

use App\Services\Delivery\CrawlInterface;
use App\Tools\Curls;

class Crawl implements CrawlInterface
{
    private $company = 'ヤマト運輸';

    /**
     * 请求url，抓取页面数据
     * @param $trackingNumber
     * @return array
     */
    public function deliveryInfo($trackingNumber)
    {
        $url = 'https://toi.kuronekoyamato.co.jp/cgi-bin/tneko';
        $data = http_build_query(['number01'=>$trackingNumber]);

        $result = Curls::send($url, 'POST', $data);

        return $this->formatResult($result);
    }

    /**
     * 格式化数据
     * @param $html
     * @return array
     */
    private function formatResult($html)
    {
        $return = array();
        $data = array();

        if (!$html) {
            $return['code'] = 'E7001';
            $return['message'] = 'no deliver data';
            return $return;
        }

        $searchFailedStatus = ['伝票番号誤り', '伝票番号未登録'];

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $dom->normalize();
        $xpath = new \DOMXPath($dom);

        $status = $xpath->query('//h4[contains(@class, "tracking-invoice-block-state-title")]')->item(0)->nodeValue;

        if (in_array(trim($status), $searchFailedStatus)) {
            $return['code'] = 'E7001';
            $return['message'] = trim($status);
            return $return;
        }

        $keys = $xpath->query('//div[contains(@class, "tracking-invoice-block-detail")]//div[contains(@class, "item")]');
        $values = $xpath->query('//div[contains(@class, "tracking-invoice-block-detail")]//div[contains(@class, "date")]');

        for ($i=0;$i<$keys->length;$i++) {
            $data[trim($keys->item($i)->nodeValue)] = trim($values->item($i)->nodeValue);
        }

        $searchOK = $data['荷物受付'] ?? $data['発送済み'] ?? null;

        if ($searchOK) {
            $shippingDate = $data['荷物受付'] ?? $data['発送済み'];//result: 12月08日 18:05
            $shippingDate = preg_replace('/\D/', '', $shippingDate);//result: 12081805
            $shippingTime = substr_replace(substr($shippingDate, -4), ':', 2, 0);//result: 18:05
            $shippingDay = substr_replace(substr($shippingDate, 0, 4), '-', 2, 0);//result: 12-07
            $shippingDate = date('Y-') . $shippingDay;//result: 2023-12-07

            /** 假如货物接收时间是未来的时间，这肯定是上一年，需要另处理（过年时发生的问题） */
            if (strtotime($shippingDate . ' ' . $shippingTime . ':00') > time()) {
                $shippingDate = (date('Y') - 1) . '-' . $shippingDay;
            }

            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result']['shipping_date'] = $shippingDate;
            $return['result']['info'] = $data;
        } else {
            $return['code'] = 'E7001';
            $return['message'] = 'no deliver data';
        }

        return $return;
    }
}
