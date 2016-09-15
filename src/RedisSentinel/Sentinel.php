<?php
namespace think\sentinel\RedisSentinel;
use think\Log;
use think\App;

/**
 * Class Sentinel 连接Redis Sentinel
 * @package RedisSentinel
 * @author tantan <18725648509@163.com>
 */
class Sentinel {

    protected $_error = false;

    protected $_master = [];

    protected $_slaves = [];

    protected $_sentinels = [];

    protected static $_master_name = '';

    protected static $_Clients = [];

    protected static $_writeClient = null;

    protected static $_readClient = null;

    /**
     * Sentinel constructor.
     * @param string $master_name 哨兵集群名称
     * @param array $Clients 客户端对象
\     */
    public function __construct($master_name,$Clients = [])
    {
        self::$_master_name = $master_name;
        self::$_Clients = $Clients;
        $this->_connect();
    }
    /**
     * 链接哨兵系统
     */
    protected function _connect()
    {
        foreach(self::$_Clients as $Client){
            try {
                $this->_master = $Client->master(self::$_master_name);
                $this->_slaves  = $Client->slaves(self::$_master_name);
                $this->_sentinels = $Client->sentinels(self::$_master_name);

                break;
            } catch (ConnectionTcpExecption $e) {
                $this->_error = true;
                $this->_writeOutputException($Client, $e);
            }
        }
    }

    /**
     * 写入哨兵客户端连接错误日志
     * @param object $Client 客户端链接
     * @param object $e 失败事件
     */
    protected function _writeOutputException($Client, $e)
    {
        //记录log
        Log::record('[Cache] Redis Sentienl Is Down:'.$Client->getHost().':'.$Client->getPort(),Log::ERROR);
    }
    /**
     * 添加客户端链接
     * @param object $Client 哨兵客户端连接
     */
    public function add(Client $Client)
    {
        self::$_Clients[] = $Client;
        $this->_connect();
    }
    public function getReadConn()
    {
        if(!self::$_readClient)
        {
            for($i = 0;$i < 3;$i++)
            {
                foreach($this->_slaves as $v)
                {
                    $redis = new \Redis();
                    if($redis->connect($v['ip'],$v['port']))
                    {
                        self::$_readClient = $redis;
                        App::$debug && Log::record("[ CACHE ] INIT Redis : {$v['ip']}:{$v['port']} slave", Log::ALERT);
                        return self::$_readClient;
                    }
                }
                $this->_connect();
            }
        }
        return self::$_readClient;
    }
    public function getWriteConn()
    {
        if(!self::$_writeClient)
        {
            for($i = 0;$i < 3;$i++)
            {
                foreach($this->_master as $v)
                {
                    $redis = new \Redis();
                    if($redis->connect($v['ip'],$v['port']))
                    {
                        self::$_writeClient = $redis;
                        App::$debug && Log::record("[ CACHE ] INIT Redis : {$v['ip']}:{$v['port']} master", Log::ALERT);
                        return self::$_writeClient;
                    }
                }
                $this->_connect();
            }
        }
        return self::$_writeClient;
    }
    /**
     * 获得主服务器信息
     * @return array
     */
    public function getMaster()
    {
        return $this->_master[0];
    }

    /**
     * 获得主服务器列表
     * @return array
     */
    public function getMasters()
    {
        return $this->_master;
    }

    /**
     * 获得从服务器列表
     * @return array 从服务器列表
     */
    public function getSlaves()
    {
        return $this->_slaves;
    }
    /**
     * 获得哨兵列表
     * @return array
     */
    public function getSentinels()
    {
        return $this->_sentinels;
    }

    /**
     * 返回true表示哨兵列表需要更新
     * @return bool
     */
    public function getSenStatus()
    {
        return $this->_error;
    }
    /**
     * @return mixed
     */
    public function getOneSlave()
    {
        $slaves = $this->getSlaves();
        $idx = rand(0, count($slaves) - 1);
        return $slaves[$idx];
    }

    /**
     * @return mixed
     */
    public function getOneMaster()
    {
        $masters = $this->getMasters();
        $idx = rand(0, count($masters) - 1);
        return $masters[$idx];
    }

}