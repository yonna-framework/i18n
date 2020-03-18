<?php

namespace Yonna\I18n;

class BaiduApi
{

    /**
     * 这里只收录少量
     * @see http://api.fanyi.baidu.com/api/trans/product/apidoc#joinFile
     */
    const LANG = [
        'zh_cn' => 'zh',    // 中文
        'zh_hk' => 'cht',    // 繁体中文
        'zh_tw' => 'cht',    // 繁体中文
        'en_us' => 'en',    // 英语
        'ja_jp' => 'jp',    // 日语
        'ko_kr' => 'kor',    // 韩语
    ];

    /**
     * 通用翻译API
     * @param $query
     * @param $to
     * @return bool
     */
    public static function translate($query, $to)
    {
        if (!$to) {
            return true;
        }
        if (!Config::getBaidu()) {
            trigger_error('Set Config for BaiduApi.');
            return true;
        }
    }

}