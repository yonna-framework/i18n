<?php

namespace Yonna\I18n;

use Throwable;
use Yonna\Database\DB;
use Yonna\Database\Driver\Mongo;
use Yonna\Database\Driver\Mysql;
use Yonna\Database\Driver\Pdo\Where as Pw;
use Yonna\Database\Driver\Mdo\Where as Mw;
use Yonna\Database\Driver\Pgsql;
use Yonna\Database\Driver\Redis;
use Yonna\Log\Log;
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
     * 个人单号最大 9 QTS
     * 暂可请求百度通用翻译API
     *
     * @throws null
     */
    private function auto()
    {
        if (!Config::getAuto()) {
            return;
        }
        if (!Config::getBaidu()) {
            return;
        }
        $rds = DB::redis(Config::getAuto());
        if (($rds instanceof Redis) === false) {
            Exception::params('Auto Translate Should use Redis Database Driver.');
        }

        $bdLimit = count(Config::getBaidu()) * 4;

        $rk = $this->store . 'qts';
        if ((int)$rds->gcr($rk) >= $bdLimit) {
            return;
        }
        $multi = null;
        $db = DB::connect($this->config);
        if ($db instanceof Mongo) {
            $multi = $db->collection("{$this->store}")
                ->or(function (Mw $w) {
                    foreach (self::ALLOW_LANG as $v) {
                        $w->equalTo($v, '');
                    }
                })
                ->or(function (Mw $w) {
                    foreach (self::ALLOW_LANG as $v) {
                        $w->isNull($v);
                    }
                })
                ->limit(2)
                ->multi();
        } elseif ($db instanceof Mysql) {
            $multi = $db->table($this->store)
                ->or(function (Pw $w) {
                    foreach (self::ALLOW_LANG as $v) {
                        $w->equalTo($v, '');
                    }
                })
                ->limit(2)
                ->multi();
        } elseif ($db instanceof Pgsql) {
            $multi = $db->schemas('public')->table($this->store)
                ->or(function (Pw $w) {
                    foreach (self::ALLOW_LANG as $v) {
                        $w->equalTo($v, '');
                    }
                })
                ->limit(2)
                ->multi();
        } else {
            Exception::database('Set Database for Support Driver.');
        }
        if ($multi) {
            $bi = 0;
            $translates = [];
            $mongoRecord = [];
            foreach ($multi as $one) {
                foreach (self::ALLOW_LANG as $v) {
                    if (empty($one[$this->store . '_' . $v])) {
                        $bi++;
                        if ($bi > $bdLimit) {
                            break;
                        }
                        $uk = $one[$this->store . '_unique_key'];
                        $q = $uk;
                        $from = 'auto';
                        // 有英语用英语，无需怀疑
                        if (!empty($one[$this->store . '_en_us'])) {
                            $q = $one[$this->store . '_en_us'];
                            $from = 'en_us';
                        }
                        $translates[] = [
                            'uk' => $uk,
                            'q' => $q,
                            'from' => $from,
                            'to' => $v
                        ];
                        $mongoRecord[$uk] = $one;
                    }
                }
            }
            $rds->incr($rk, $bi);
            if ($translates) {
                try {
                    BaiduApi::translate(
                        $translates,
                        function (array $res) use ($db, $mongoRecord) {
                            $mixed = [];
                            foreach ($res as $r) {
                                if (empty($mixed[$r['uk']])) {
                                    $mixed[$r['uk']] = [];
                                }
                                // 汉字圈去掉空格
                                if (in_array($r['to'], [
                                    'zh_cn', 'zh_hk', 'zh_tw',
                                    'ja_jp', 'ko_kr',
                                ])) {
                                    $r['dst'] = str_replace(' ', '', $r['dst']);
                                }
                                if ($r['to'] === 'en_us') {
                                    $r['dst'] = strtolower($r['dst']);
                                }
                                $mixed[$r['uk']][$r['to']] = $r['dst'];
                            }
                            if ($mixed) {
                                foreach ($mixed as $xk => $xv) {
                                    if ($db instanceof Mongo) {
                                        foreach ($mongoRecord[$xk] as $mrk => $mr) {
                                            $mrk = str_replace($this->store . '_', '', $mrk);
                                            if ($mrk != '_id' && empty($xv[$mrk])) {
                                                $xv[$mrk] = $mr;
                                            }
                                        }
                                        $db->collection($this->store)->equalTo('unique_key', $xk)->update($xv);
                                    } elseif ($db instanceof Mysql) {
                                        $db->table($this->store)->equalTo('unique_key', $xk)->update($xv);
                                    } elseif ($db instanceof Pgsql) {
                                        $db->schemas('public')->table($this->store)->equalTo('unique_key', $xk)->update($xv);
                                    }
                                }
                            }
                        }
                    );
                    $rds->decr($rk, $bi);
                } catch (Throwable $e) {
                    $rds->decr($rk, $bi);
                    Exception::origin($e);
                }
            }
            $rds->expire($rk, 30);
        }
    }

    /**
     * 初始化数据
     * @return bool
     * @throws Exception\DatabaseException
     */
    public function init()
    {
        $fileData = [];
        foreach (self::ALLOW_LANG as $al) {
            if (is_file(__DIR__ . "/lang/{$al}.json")) {
                $fileData[$al] = json_decode(file_get_contents(__DIR__ . "/lang/{$al}.json"), true);
            } else {
                $fileData[$al] = [];
            }
        }
        $i18nData = [];
        foreach ($fileData['en_us'] as $k => $v) {
            $i18nData[] = [
                "unique_key" => $k,
                "en_us" => $v,
                "zh_cn" => $fileData['zh_cn'][$k] ?? '',
                'zh_hk' => $fileData['zh_hk'][$k] ?? '',
                'zh_tw' => $fileData['zh_tw'][$k] ?? '',
                'ja_jp' => $fileData['ja_jp'][$k] ?? '',
                'ko_kr' => $fileData['ko_kr'][$k] ?? '',
            ];
        }
        $db = DB::connect($this->config);
        if ($db instanceof Mongo) {
            $db->collection("{$this->store}")->drop(true);
            $db->collection("{$this->store}")->insertAll($i18nData);
        } elseif ($db instanceof Mysql) {
            $db->query("CREATE TABLE IF NOT EXISTS `{$this->store}`(
                        `unique_key` char(255) NOT NULL DEFAULT '' COMMENT '验证key',
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
     * 备份数据到json文件
     * @throws Exception\DatabaseException
     */
    public function backup()
    {
        $data = $this->get();
        $fileData = [];
        foreach (self::ALLOW_LANG as $al) {
            $fileData[$al] = [];
        }
        foreach ($data as $d) {
            foreach (self::ALLOW_LANG as $al) {
                $fileData[$al][$d[$this->store . '_unique_key']] = $d[$this->store . '_' . $al] ?? '';
            }
        }
        foreach (self::ALLOW_LANG as $al) {
            $fn = __DIR__ . "/lang/{$al}.json";
            ksort($fileData[$al]);
            @file_put_contents($fn, json_encode($fileData[$al], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            @chmod($fn, 0644);
        }
        return true;
    }

    /**
     * 获得i18n数据
     * @return array
     * @throws Exception\DatabaseException
     */
    public function get()
    {
        $res = [];
        $db = DB::connect($this->config);
        if ($db instanceof Mongo) {
            $res = $db->collection("{$this->store}")->orderBy('unique_key', 'asc')->multi();
        } elseif ($db instanceof Mysql) {
            $res = $db->table($this->store)->orderBy('unique_key', 'asc')->multi();
        } elseif ($db instanceof Pgsql) {
            $res = $db->schemas('public')->table($this->store)->orderBy('unique_key', 'asc')->multi();
        } else {
            Exception::database('Set Database for Support Driver.');
        }
        $this->auto();
        return $res;
    }

    /**
     * 分页获得数据
     * @param int $current
     * @param int $per
     * @param array $filter
     * @return array
     * @throws Exception\DatabaseException
     */
    public function page($current = 1, $per = 10, $filter = [])
    {
        $res = [];
        $db = DB::connect($this->config);
        if ($db instanceof Mongo) {
            $obj = $db->collection("{$this->store}")->orderBy('unique_key', 'asc');
        } elseif ($db instanceof Mysql) {
            $obj = $db->table($this->store)->orderBy('unique_key', 'asc');
        } elseif ($db instanceof Pgsql) {
            $obj = $db->schemas('public')->table($this->store)->orderBy('unique_key', 'asc');
        } else {
            Exception::database('Set Database for Support Driver.');
            return $res;
        }
        if (!empty($filter['unique_key'])) {
            $obj = $obj->equalTo('unique_key', $filter['unique_key']);
        }
        $res = $obj->page($current, $per);
        return $res;
    }

    /**
     * 设置一个i18n数据
     * 如果有则更新，没有则添加
     * @param $uniqueKey
     * @param array $data
     */
    public function set($uniqueKey, $data = [])
    {
        if (empty($uniqueKey)) {
            return;
        }
        $uniqueKey = strtoupper($uniqueKey);
        $data = array_filter($data);
        $db = DB::connect($this->config);
        try {
            if ($db instanceof Mongo) {
                $res = $db->collection("{$this->store}")->equalTo('unique_key', $uniqueKey)->one();
                if (!$res) {
                    $data['unique_key'] = $uniqueKey;
                    foreach (I18n::ALLOW_LANG as $l) {
                        if (!isset($data[$l])) {
                            $data[$l] = '';
                        }
                    }
                    $db->collection("{$this->store}")->insert($data);
                } else {
                    unset($res['_id']);
                    foreach ($res as $rk => $r) {
                        $rk = str_replace($this->store . '_', '', $rk);
                        if (!isset($data[$rk])) {
                            $data[$rk] = $r;
                        }
                    }
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
        } catch (Exception\DatabaseException $e) {
            Log::file()->throwable($e, 'I18N');
        }
        $this->auto();
    }

}