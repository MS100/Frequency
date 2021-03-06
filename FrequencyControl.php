<?php

class FrequencyControl{
    private static $client;//memcache客户端连接

    private static $retry_lock_num = 5;//加锁重试次数
    private static $retry_lock_time = 1000;//加锁重试间隔时间

    private static $time_offset = 0;//时差偏移

    private static $rules = array();//规则

    const LOCK_SUFFIX = '_$lock';//加锁后缀
    const TAIL_SUFFIX = '_$tail';//游标后缀

    const TYPE_FIXED_PERIOD = 'fixed';//固定周期
    const TYPE_ACTIVE_PERIOD = 'active';//活动周期

    private function __construct(){

    }

    /**
     * 初始化
     * @param $config
     */
    public static function initialize($config){
        self::$time_offset = intval(date('Z'));
        self::$client = new Memcached();

        foreach(array('retry_lock_num', 'retry_lock_time', 'rules', 'separator') as $v){
            if(isset($config[$v])){
                self::$$v = $config[$v];
            }
        }


        foreach($config['servers'] as $item){
            $server = array();
            $server[] = $item['hostname'];
            $server[] = $item['port'];
            $server[] = $item['weight'];
            $servers[] = $server;
        }
        self::$client->addServers($servers);
    }

    /**
     * 加载配置
     * @param $acts
     * @param $fields
     * @return array
     */
    private static function get_config($acts, $fields){
        $results = array();

        if(!is_array($acts)){
            $acts = array($acts);
        }

        foreach($acts as $act){
            if(!isset(self::$rules[$act])) continue;

            foreach(self::$rules[$act] as $rule){
                if(isset($fields[$rule['field']])){
                    if(in_array($fields[$rule['field']], $rule['white'])){
                        //continue;
                        return array();
                    }

                    $temp['key'] = sprintf('%s_%s_%s_%s_%s:%s',
                        __CLASS__,
                        $act,
                        $rule['field'],
                        $rule['type'],
                        $rule['period'],
                        $fields[$rule['field']]
                    );
                    $temp['type'] = $rule['type'];
                    $temp['period'] = $rule['period'];
                    $temp['limit_num'] = $rule['limit_num'];
                    $results[] = $temp;
                }
            }
        }

        return $results;
    }

    /**
     * 检查请求
     * @param $acts string|array 行为，可以是字符串，也可以是一个数组
     * @param $fields
     * @param bool $do_request
     * @return integer
     */
    public static function check($acts, $fields, $do_request = false){
        $items = self::get_config($acts, $fields);

        if(empty($items)){
            return 0;
        }

        $time = self::_check($items);
        if($time){
            return $time;
        }

        if($do_request){
            self::_request($items);
        }

        return 0;
    }

    private static function _check($items){
        $time_array = array();
        foreach($items as $item){
            switch($item['type']){
                case self::TYPE_ACTIVE_PERIOD:
                    if(!self::lock($item['key'])){
                        $time_array[] = 1;
                        continue;
                    }
                    $tail = intval(self::$client->get($item['key'] . self::TAIL_SUFFIX));
                    $store_time = self::$client->get($item['key'] . '_' . $tail);
                    self::unlock($item['key']);
                    if($store_time){
                        $time_array[] = $store_time + $item['period'] - time();
                    }

                    break;
                case self::TYPE_FIXED_PERIOD:
                    $time = time() + self::$time_offset;//加时区修正
                    $tail = ceil($time / $item['period']);

                    if(intval(self::$client->get($item['key'] . '_' . $tail)) >= $item['limit_num']){
                        $time_array[] = $tail * $item['period'] - $time;
                    }
                    break;
            }
        }
        if(empty($time_array)) return 0;


        $time = max($time_array);

        return $time;
    }

    /**
     * 记录请求
     *
     * @param $acts 行为，可以是字符串，也可以是一个数组
     * @param $fields array('field1'=>'value1', 'field2'=>'value2')
     * @return bool
     */
    public static function request($acts, $fields){
        $items = self::get_config($acts, $fields);

        if(!empty($items)){
            self::_request($items);
        }

        return true;
    }

    private static function _request($items){
        foreach($items as $item){
            switch($item['type']){
                case self::TYPE_ACTIVE_PERIOD:
                    if(!self::lock($item['key'])){
                        continue;
                    }
                    $tail = intval(self::$client->get($item['key'] . self::TAIL_SUFFIX));

                    self::$client->set($item['key'] . '_' . $tail, time(), $item['period']);

                    if(++$tail >= $item['limit_num']){
                        $tail = 0;
                    }

                    self::$client->set($item['key'] . self::TAIL_SUFFIX, $tail, $item['period']);

                    self::unlock($item['key']);
                    break;
                case self::TYPE_FIXED_PERIOD:
                    $time = time() + self::$time_offset;//加时区修正
                    $tail = ceil($time / $item['period']);
                    if(!self::$client->increment($item['key'] . '_' . $tail)){//这里极端情况下可能会整数溢出
                        self::$client->set($item['key'] . '_' . $tail, 1, $item['period']);
                    }

                    break;
            }
        }
    }


    /**
     * 加锁
     * @param $key
     * @return bool
     */
    private static function lock($key){
        $t = 0;
        while(!self::$client->add($key . self::LOCK_SUFFIX, 1, 1)){
            if($t++ >= self::$retry_lock_num){//尝试等待N次
                return false;
            }
            usleep(self::$retry_lock_time);
        }

        return true;
    }

    /**
     * 解锁
     * @param $key
     */
    private static function unlock($key){
        self::$client->delete($key . self::LOCK_SUFFIX);
    }

}
