<?php

namespace Yonna\I18n;

use Yonna\Database\DB;
use Yonna\Database\Driver\Mongo;
use Yonna\Database\Driver\Mysql;
use Yonna\Database\Driver\Pgsql;

class I18n
{

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
     * 初始化数据
     * @return bool
     * @throws null
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

        } else {
            throw new \Exception('Set Database for Support Driver.');
        }
        return true;
    }

    /**
     * 获得i18n数据
     * @return array
     * @throws null
     */
    public function get()
    {
        $res = [];
        try {
            $db = DB::connect($this->config);
            if ($db instanceof Mongo) {
                $res = $db->collection("{$this->store}")->multi();
            } elseif ($db instanceof Mysql) {
                $res = $db->table('i18n')->multi();
            } elseif ($db instanceof Pgsql) {
                $res = $db->schemas('public')->table('i18n')->multi();
            } else {
                throw new \Exception('Set Database for Support Driver.');
            }
        } catch (\Throwable $e) {
            // nothing
        }
        return $res;
    }

}