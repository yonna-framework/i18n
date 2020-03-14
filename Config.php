<?php

namespace Yonna\I18n;

class Config
{

    private static $database = null;

    /**
     * @return null
     */
    public static function getDatabase()
    {
        return self::$database;
    }

    /**
     * @param null $database
     */
    public static function setDatabase($database): void
    {
        self::$database = $database;
    }

}