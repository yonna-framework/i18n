<?php

namespace Yonna\I18n;

use Yonna\IO\Request;
use Yonna\Middleware\Before;
use Yonna\Throwable\Exception;
use Yonna\Scope\Config;

class Debug extends Before
{

    /**
     * @return Request
     * @throws Exception\DebugException
     */
    public function handle(): Request
    {
        if (getenv('IS_DEBUG') === 'false') {
            Exception::debug('NOT_DEBUG');
        }
        return $this->request();
    }

}

class Scope
{

    public static function conf()
    {
        Config::middleware( // only debug
            [
                Debug::class,
            ],
            function () {
                Config::group(['i18n'], function () {
                    Config::delete('init', function () {
                        return (new I18n())->init();
                    });
                    Config::delete('set', function (Request $request) {
                        $input = $request->getInput();
                        $data = [];
                        foreach (I18n::ALLOW_LANG as $lang) {
                            $data[$lang] = $input[$lang] ?? '';
                        }
                        (new I18n())->set($input['unique_key'], $data);
                        return true;
                    });
                });
            }
        );
        Config::group(['i18n'], function () {
            Config::put('backup', function () {
                return (new I18n())->backup();
            });
            Config::post('all', function () {
                return (new I18n())->get();
            });
            Config::post('page', function (Request $request) {
                $input = $request->getInput();
                return (new I18n())->page(
                    $input['current'] ?? 1,
                    $input['per'] ?? 10,
                    [
                        'unique_key' => $input['unique_key'] ?? null,
                    ],
                    );
            });
        });
    }

}