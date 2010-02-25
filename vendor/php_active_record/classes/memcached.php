<?php

class Memcached
{
    public static function get($key)
    {
        if(!self::connected()) return false;
        return $GLOBALS['memcached_connection']->get($key);
    }
    
    public static function add($key, $value, $expire = 10)
    {
        if(!self::connected()) return false;
        return $GLOBALS['memcached_connection']->add($key, $value, false, $expire);
    }
    
    public static function set($key, $value, $expire = 10)
    {
        if(!self::connected()) return false;
        return $GLOBALS['memcached_connection']->set($key, $value, false, $expire);
    }
    
    public static function delete($key, $timeout = 0)
    {
        if(!self::connected()) return false;
        return $GLOBALS['memcached_connection']->delete($key, $timeout);
    }
    
    public static function flush()
    {
        if(!self::connected()) return false;
        return $GLOBALS['memcached_connection']->flush();
    }
    
    private static function connected()
    {
        if(@!$GLOBALS['memcached_connection']) return false;
        return true;
    }
}

?>