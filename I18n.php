<?php

namespace Yonna\I18n;

use Yonna\Database\DB;
use Yonna\Database\Driver\Mongo;
use Yonna\Database\Driver\Mysql;
use Yonna\Database\Driver\Pgsql;
use Yonna\Database\Driver\Redis;
use Yonna\Throwable\Exception;

class I18n
{

    const ALLOW_LANG = [
        'zh_cn',
        'zh_hk',
        'zh_tw',
        'en_us',
        'ja_jp',
        'ko_kr',
    ];

    private $store = 'yonna_i18n';
    private $config = null;

    /**
     * check yonna/database
     * DatabaseLog constructor.
     */
    public function __construct()
    {
        if (!class_exists(DB::class)) {
            trigger_error('If you want to use database log,install composer package yonna/database please.');
            return;
        }
        if (Config::getDatabase() === null) {
            trigger_error('Set Database for DatabaseLog.');
            return;
        }
        $this->config = Config::getDatabase();
    }

    /**
     * 自动翻译机
     * 最大 9 QTS
     * 暂可请求百度通用翻译API
     *
     * @return bool
     * @throws Exception\DatabaseException
     */
    private function auto()
    {
        if (!Config::getAuto()) {
            trigger_error('Set Config for Auto Translate.');
            return false;
        }
        if (!Config::getBaidu()) {
            trigger_error('Set Config for BaiduApi.');
            return false;
        }
        $rds = DB::redis(Config::getAuto());
        if (($rds instanceof Redis) === false) {
            trigger_error('Auto Translate Should use Redis Database Driver.');
            return false;
        }
        $rk = $this->store . 'QTS';
        if ((int)$rds->get($rk) >= 9) {
            return true;
        }
        $db = DB::connect($this->config);
        if ($db instanceof Mongo) {
            $one = $db->collection("{$this->store}")->one();
        } elseif ($db instanceof Mysql) {
            $table = $db->table($this->store)->fetchQuery();
            $one = $table->or(
                function () use ($table) {
                    foreach (self::ALLOW_LANG as $v) {
                        $table->equalTo($v, '');
                    }
                    $table->and(
                        function () use ($table) {
                            foreach (self::ALLOW_LANG as $v) {
                                $table->equalTo($v, '');
                            }
                        });
                })
                ->equalTo('zh_cn', 'x')
                ->one();
            var_dump($one);
            exit();
        } elseif ($db instanceof Pgsql) {
            $one = $db->schemas('public')->table($this->store)->one();
        } else {
            Exception::database('Set Database for Support Driver.');
        }
        // $rds->incr($rk);
        return true;
    }

    /**
     * 初始化数据
     * @return bool
     * @throws Exception\DatabaseException
     */
    public function init()
    {
        $en_us = json_decode(file_get_contents(__DIR__ . '/lang/en_us.json'), true);
        $zh_cn = json_decode(file_get_contents(__DIR__ . '/lang/zh_cn.json'), true);
        $i18nData = [];
        foreach ($en_us as $k => $v) {
            $i18nData[] = [
                "unique_key" => $k,
                "source" => "default",
                "en_us" => $v,
                "zh_cn" => $zh_cn[$k] ?? '',
            ];
        }
        $db = DB::connect($this->config);
        if ($db instanceof Mongo) {
            $db->collection("{$this->store}")->insertAll($i18nData);
        } elseif ($db instanceof Mysql) {
            $db->query("CREATE TABLE IF NOT EXISTS `{$this->store}`(
                        `unique_key` char(255) NOT NULL DEFAULT '' COMMENT '验证key',
                        `source`     char(255) NOT NULL DEFAULT 'new' COMMENT '来源',
                        `zh_cn`      char(255) NOT NULL DEFAULT '' COMMENT '简体中文',
                        `zh_hk`      char(255) NOT NULL DEFAULT '' COMMENT '香港繁体',
                        `zh_tw`      char(255) NOT NULL DEFAULT '' COMMENT '台湾繁体',
                        `en_us`      char(255) NOT NULL DEFAULT '' COMMENT '美国英语',
                        `ja_jp`      char(255) NOT NULL DEFAULT '' COMMENT '日本语',
                        `ko_kr`      char(255) NOT NULL DEFAULT '' COMMENT '韩国语',
                        PRIMARY KEY (`unique_key`)
                    ) ENGINE = INNODB COMMENT 'i18n by yonna';");
            DB::connect($this->config)->table($this->store)->truncate(true); //截断清空
            DB::connect($this->config)->table($this->store)->insertAll($i18nData);
        } elseif ($db instanceof Pgsql) {
            $db->query("CREATE TABLE IF NOT EXISTS `{$this->store}`(
                        `unique_key` text NOT NULL DEFAULT '',
                        `source`     text NOT NULL DEFAULT 'new',
                        `zh_cn`      text NOT NULL DEFAULT '',
                        `zh_hk`      text NOT NULL DEFAULT '',
                        `zh_tw`      text NOT NULL DEFAULT '',
                        `en_us`      text NOT NULL DEFAULT '',
                        `ja_jp`      text NOT NULL DEFAULT '',
                        `ko_kr`      text NOT NULL DEFAULT '',
                        PRIMARY KEY (`unique_key`)
                    ) ENGINE = INNODB COMMENT 'i18n by yonna';");
            DB::connect($this->config)->schemas('public')->table($this->store)->truncate(true); //截断清空
            DB::connect($this->config)->schemas('public')->table($this->store)->insertAll($i18nData);
        } else {
            Exception::database('Set Database for Support Driver.');
        }
        return true;
    }

    /**
     * 获得i18n数据
     * @return array
     * @throws Exception
     * @throws Exception\DatabaseException
     */
    public function get()
    {
        $res = [];
        $db = DB::connect($this->config);
        if ($db instanceof Mongo) {
            $res = $db->collection("{$this->store}")->multi();
        } elseif ($db instanceof Mysql) {
            $res = $db->table($this->store)->multi();
        } elseif ($db instanceof Pgsql) {
            $res = $db->schemas('public')->table($this->store)->multi();
        } else {
            Exception::database('Set Database for Support Driver.');
        }
        $this->auto();
        return $res;
    }

    /**
     * 设置一个i18n数据
     * 如果有则更新，没有则添加
     * @param $uniqueKey
     * @param array $data
     * @throws Exception\DatabaseException
     */
    public function set($uniqueKey, $data = [])
    {
        if (empty($uniqueKey)) {
            return;
        }
        $uniqueKey = strtoupper($uniqueKey);
        $data = array_filter($data);
        $db = DB::connect($this->config);
        if ($db instanceof Mongo) {
            $res = $db->collection("{$this->store}")->getCollection();
            if (!$res) {
                $data['unique_key'] = $uniqueKey;
                $db->collection("{$this->store}")->insert($data);
            } else {
                $db->collection("{$this->store}")->equalTo('unique_key', $uniqueKey)->update($data);
            }
        } elseif ($db instanceof Mysql) {
            $res = $db->table($this->store)->equalTo('unique_key', $uniqueKey)->one();
            if (!$res) {
                $data['unique_key'] = $uniqueKey;
                $db->table($this->store)->insert($data);
            } else {
                $db->table($this->store)->equalTo('unique_key', $uniqueKey)->update($data);
            }
        } elseif ($db instanceof Pgsql) {
            $res = $db->schemas('public')->table($this->store)->one();
            if (!$res) {
                $data['unique_key'] = $uniqueKey;
                $db->schemas('public')->table($this->store)->insert($data);
            } else {
                $db->schemas('public')->table($this->store)->equalTo('unique_key', $uniqueKey)->update($data);
            }
        } else {
            Exception::database('Set Database for Support Driver.');
        }
        $this->auto();
    }

}