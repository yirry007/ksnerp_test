<?php

namespace App\Services\Delivery\Sagawa;

use App\Services\Delivery\CrawlInterface;
use App\Tools\Curls;

class Crawl implements CrawlInterface
{
    private $company = '佐川急便';

    /**
     * 请求url，抓取页面数据
     * @param $trackingNumber
     * @return array
     */
    public function deliveryInfo($trackingNumber)
    {
        $url = 'http://k2k.sagawa-exp.co.jp/cgi-bin/mall.mmcgi?oku01=' . $trackingNumber;

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

        $keys = $xpath->query('//table[contains(@class, "ichiran-bg-msrc2")]//td[contains(@class, "ichiran-fg-msrc2-1")]');
        $values = $xpath->query('//table[contains(@class, "ichiran-bg-msrc2")]//td[contains(@class, "ichiran-fg-msrc2-2")]');

        for ($i=0;$i<$keys->length;$i++) {
            $data[trim($keys->item($i)->nodeValue)] = trim($values->item($i)->nodeValue);
        }

        $searchOK = array_key_exists('出荷日', $data);

        if ($searchOK) {
            $shippingDate = $data['出荷日'];//result: 2023年12月07日
            $shippingDate = preg_replace('/\D/', '', $shippingDate);//result: 20231207
            $shippingDate = substr_replace(substr_replace($shippingDate, '-', 6, 0), '-', 4, 0);//result: 2023-12-07

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
