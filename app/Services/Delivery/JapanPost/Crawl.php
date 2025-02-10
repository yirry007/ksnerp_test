<?php

namespace App\Services\Delivery\JapanPost;

use App\Services\Delivery\CrawlInterface;
use App\Tools\Curls;

class Crawl implements CrawlInterface
{
    private $company = '日本郵便';

    /**
     * 请求url，抓取页面数据
     * @param $trackingNumber
     * @return array
     */
    public function deliveryInfo($trackingNumber)
    {
        $url = 'https://trackings.post.japanpost.jp/services/srv/search/?requestNo1=' . $trackingNumber . '&search.x=1&search.y=1';

        $result = Curls::send($url);

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

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $dom->normalize();
        $xpath = new \DOMXPath($dom);

        $td = $xpath->query('//table[contains(@class, "tableType01") and contains(@summary, "履歴情報")]//td');

        if (!$td->length || !$shippingDate = trim($td->item(0)->nodeValue)) {
            $return['code'] = 'E7001';
            $return['message'] = 'no deliver data';
            return $return;
        }

        $shippingDate = str_replace('/', '-', $shippingDate);//result: 2023-11-27 12:10
        $shippingDate = substr($shippingDate, 0, 10);//result: 2023-11-27

        $infoTitle = '状態発生日|配送履歴|詳細|取扱局|県名等|郵便番号';
        $data[] = $infoTitle;

        $row = '';
        for ($i=0;$i<$td->length;$i++) {
            if ($i % 6 == 0) $row = '';
            if ($i % 6 > 0) $row .= '|';

            $row .= $td->item($i)->nodeValue;

            if ($i % 6 == 5) $data[] = $row;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['shipping_date'] = $shippingDate;
        $return['result']['info'] = $data;

        return $return;
    }
}
