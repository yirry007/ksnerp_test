<?php

namespace App\Tools\Image;

class Image {
    const RESIZE_WIDTH = 200;
    const RESIZE_HEIGHT = 200;

    /**
     * 根据 base64 字符串或 url 地址保存图片到本地
     * @param string $data image url address or base64 string
     * @param string $type base64 | url
     * @param boolean $compress
     * @return bool|string
     */
    public static function save($data, $type='base64', $compress=true)
    {
        if ($type == 'url') {
            $result = self::saveFromUrl($data, $compress);
        } else {
            $result = self::saveFromBase64($data, $compress);
        }

        return $result;
    }

    /**
     * base64 格式读取图片
     * @param $imagePath
     * @return string
     */
    public static function readAsBase64($imagePath)
    {
        $image = file_get_contents(public_path($imagePath));
        $base64String = base64_encode($image);

        $imageInfo = getimagesize(public_path($imagePath));

        if ($imageInfo[2] == IMAGETYPE_JPEG) {
            $imageType = 'jpg';
        } elseif ($imageInfo[2] == IMAGETYPE_GIF) {
            $imageType = 'gif';
        } elseif ($imageInfo[2] == IMAGETYPE_PNG) {
            $imageType = 'png';
        } else {
            $imageType = '';
        }

        return $imageType ? 'data:image/' . $imageType . ';base64,' . $base64String : '';
    }

    /**
     * 根据 url 地址下载并保存图片
     * @param $data
     * @param bool $compress
     * @return bool|string
     */
    private static function saveFromUrl($data, $compress=true)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, TRUE);  //需要response header
        curl_setopt($ch, CURLOPT_NOBODY, FALSE);  //需要response body
        $response = curl_exec($ch);

        $body = '';//存储图片二进制数据
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == '200') {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE); //头信息size
            $body = substr($response, $headerSize);
        }
        curl_close($ch);

        if (!$body) return false;

        $path = self::getPath();
        $res = file_put_contents($path['savepath'], $body);

        if (!$res) return false;

        if ($compress) {
            try {
                $simpleImage = new SimpleImage($path['savepath']);
            } catch (\Exception $e) {
                return $path['filepath'];
            }

            /** 图片缩放 */
            $simpleImage->resize(self::RESIZE_WIDTH, self::RESIZE_HEIGHT);
            $simpleImage->save($path['savepath']);
        }

        return $path['filepath'];
    }

    /**
     * base64 字符串转为图片格式保存到本地
     * @param $data
     * @param bool $compress
     * @return bool|string
     */
    private static function saveFromBase64($data, $compress=true)
    {
        $file = explode(',', $data);
        $file = $file[1];
        $path = self::getPath();

        $res = file_put_contents($path['savepath'], base64_decode($file));

        if (!$res) return false;

        if ($compress) {
            try {
                $simpleImage = new SimpleImage($path['savepath']);
            } catch (\Exception $e) {
                return $path['filepath'];
            }

            /** 图片缩放 */
            $simpleImage->resize(self::RESIZE_WIDTH, self::RESIZE_HEIGHT);
            $simpleImage->save($path['savepath']);
        }

        return $path['filepath'];
    }

    /**
     * 获取文件保存路径
     * @param string $ext
     * @return array
     */
    private static function getPath($ext = 'jpg')
    {
        $filepath = 'upload/' . date('Ym') . '/' . date('d') . '/';
        $filename = date('YmdHis') . mt_rand(100000, 999999) . '.' . $ext;


        if (!is_dir(public_path($filepath))) {
            mkdir(public_path($filepath), 0777, true);
        }

        return [
            'filepath'=>$filepath . $filename,
            'savepath'=>public_path($filepath . $filename)
        ];
    }
}
