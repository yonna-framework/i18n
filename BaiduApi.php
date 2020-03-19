<?php

namespace Yonna\I18n;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Yonna\Database\DB;
use Yonna\Database\Driver\Redis;
use Yonna\Foundation\Str;
use Yonna\Log\Log;
use Yonna\Throwable\Exception;

class BaiduApi
{

    const KEY = 'baidu_api';

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

    const ERRORS = [
        '52000' => '成功	',
        '52001' => '请求超时,重试',
        '52002' => '系统错误,重试',
        '52003' => '未授权用户,检查您的 appid 是否正确，或者服务是否开通',
        '54000' => '必填参数为空,检查是否少传参数',
        '54001' => '签名错误,请检查您的签名生成方法',
        '54003' => '访问频率受限,请降低您的调用频率',
        '54004' => '账户余额不足,请前往管理控制台为账户充值',
        '54005' => '长query请求频繁,请降低长query的发送频率，3s后再试',
        '58000' => '客户端IP非法,检查个人资料里填写的 IP地址 是否正确,可前往管理控制平台修改IP限制，IP可留空',
        '58001' => '译文语言方向不支持,检查译文语言是否在语言列表里',
        '58002' => '服务当前已关闭,请前往管理控制台开启服务',
        '90107' => '认证未通过或未生效,请前往我的认证查看认证进度',
    ];

    private static $client = null;

    private static function client()
    {
        if (self::$client === null) {
            self::$client = new Client();
        }
        return self::$client;
    }

    /**
     * 随机分配一个签名用于使用
     * @param string $query
     * @return array
     */
    private static function certificate(string $query): array
    {
        $confCount = count(Config::getBaidu());
        if ($confCount === 1) {
            $config = current(Config::getBaidu());
        } else {
            $config = Config::getBaidu()[rand(0, count(Config::getBaidu()) - 1)]; // 随机取一个配置，分散使用
        }
        $appid = $config[0];
        $salt = Str::randomNum(16);
        $sign = md5($appid . urlencode($query) . $salt . $config[1]);
        return [
            'appid' => $appid,
            'salt' => $salt,
            'sign' => $sign,
        ];
    }

    /**
     * 通用翻译API
     * @param $query
     * @param $from
     * @param $to
     * @param Closure $call
     * @throws Exception\DatabaseException
     * @throws Exception\ParamsException
     */
    public static function translate($query, $from, $to, Closure $call)
    {
        if (!$query || !$from || !$to) {
            Exception::params('BaiduApi: query & from & to');
        }
        if (!Config::getBaidu()) {
            Exception::params('BaiduApi: Please set config');
        }
        if (empty(self::LANG[$to])) {
            Exception::params("BaiduApi: Un support lang <{$to}>");
        }
        $certificate = self::certificate($query);
        $rds = DB::redis(Config::getAuto());
        if (($rds instanceof Redis) === false) {
            Exception::params('Auto Translate Should use Redis Database Driver.');
            return;
        }
        $rk = self::KEY . ":{$query}_" . self::LANG[$to];
        $cache = $rds->get($rk);
        if ($cache) {
            $call($to, urldecode($cache['trans_result'][0]['dst']));
            return;
        }
        try {
            $queryString = http_build_query([
                'q' => urlencode($query), // 请求翻译query UTF-8编码
                'from' => self::LANG[$from] ?? $from, // 翻译源语言 语言列表(可设置为auto)
                'to' => self::LANG[$to],// 译文语言 语言列表(不可设置为auto)
                'appid' => $certificate['appid'],// APP ID 可在管理控制台查看
                'salt' => $certificate['salt'],// 随机数
                'sign' => $certificate['sign'],// 签名 appid+q+salt+密钥 的MD5值
            ]);
            $request = new Request('GET', 'http://api.fanyi.baidu.com/api/trans/vip/translate?' . $queryString);
            $promise = self::client()->sendAsync($request, ['timeout' => 5])->then(
                function (ResponseInterface $response) use ($rds, $rk, $call, $query, $to) {
                    if ($response->getStatusCode() === 200) {
                        $res = $response->getBody()->__tostring();
                        $res = json_decode($res, true);
                        if (!empty($res['error_code'])) {
                            Log::file()->error([
                                'msg' => "{$res['error_msg']} " . self::ERRORS[$res['error_code']] ?? ''
                            ], self::KEY);
                        } else {
                            $rds->set($rk, $res, 10);
                            $call($to, urldecode($res['trans_result'][0]['dst']));
                        }
                    } else {
                        Log::file()->error(['msg' => $response->getReasonPhrase()], self::KEY);
                    }
                },
                function (RequestException $e) {
                    Log::file()->error([
                        'msg' => '[' . $e->getRequest()->getMethod() . ']' . $e->getMessage()
                    ], self::KEY);
                }
            );
            // $promise->wait();
        } catch (\Throwable $e) {
            Log::file()->throwable($e, self::KEY);
        }
    }

}