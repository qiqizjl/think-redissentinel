<?php

namespace think\sentinel\RedisSentinel;
/**
 * Class Client Redis Sentinel客户端
 * @package RedisSentinel
 * @author tantan <18725648509@163.com>
 */
class Client {
    protected $_socket = null;
    protected $_host;
    protected $_port;

    public function __construct($h, $p = 26379)
    {
        $this->_host = $h;
        $this->_port = $p;
    }
    public function __destruct()
    {
        if ($this->_socket) 
            $this->_close();
    }
    public function getHost()
    {
        return $this->_host;
    }
    public function getPort()
    {
        return $this->_port;
    }
    public function selfInfo()
    {
        return [
            'ip' => $this->_host,
            'port' => $this->_port
        ];
    }
    /*!
     * PING
     * @return boolean true 连通成功
     * @return boolean false 连通失敗
     */
    public function ping()
    {
        $this->_connect();
        $this->_write('PING');
        $this->_write('QUIT');
        $data = $this->_get();
        $this->_close();
        return ($data === '+PONG');
    }
    /**
     * 获得所有的主服务器
     * @return array
     * @throws ConnectionTcpExecption
     */
    public function masters()
    {
        return $this->_command('masters');
    }
    /**
     * SENTINEL master
     * @param string $master 哨兵集群名称
     * @return array 主服务器列表
     * @throws ConnectionTcpExecption
     */
    public function master($master){
        return $this->_command('master',$master);
    }
    /**
     * SENTINEL slaves
     * @param string $master 哨兵集群名称
     * @return array
     * @throws ConnectionTcpExecption
     */
    public function slaves($master)
    {
        return $this->_command('slaves',$master);
    }

    /**
     * SENTINEL sentinels
     * @param string $master 哨兵集群名称
     * @return array 返回哨兵集群信息
     * @throws ConnectionTcpExecption
     */
    public function sentinels($master)
    {
        $senObj = [];
        $data = $this->_command('sentinels', $master);
        $data[] = $this->selfInfo();
        foreach($data as $v)
        {
            $senObj[] = new self($v['ip'],$v['port']);
        }
        return $senObj;
    }

    /**
     * Sentinel 连接
     * @return boolean true  连接成功
     * @return boolean false 连接失敗
     */
    protected function _connect()
    {
        $this->_socket = @fsockopen($this->_host, $this->_port, $errno, $errstr, 1);
        if ( ! $this->_socket ) {
            throw new ConnectionTcpExecption($errstr);
        }
    }

    /**
     * @param string $command 发送哨兵命令
     * @param string $master 哨兵集群名称
     */
    protected function _command($command, $master = '')
    {
        $command = $master ? $command . ' ' . $master : $command;
        $this->_connect();
        $this->_write('SENTINEL ' . $command);
        $this->_write('QUIT');
        $data = $this->_extract($this->_get());
        $this->_close();
        return $data;
    }
    /**
     * Sentinel 关闭
     * @return boolean true  切断成功
     * @return boolean false 切断失敗
     */
    protected function _close()
    {
        $ret = @fclose($this->_socket);
        $this->_socket = null;
        return $ret;
    }

    /**
     * Sentinel 接受值
     * @return boolean true  有内容
     * @return boolean false 无内容
     */
    protected function _receiving()
    {
        return !feof($this->_socket);
    }
    /**
     * Sentinel 写
     * @param $c string send写入数据
     * @return mixed integer
     * @return mixed boolean false
     */
    protected function _write($c)
    {
        return fwrite($this->_socket, $c . "\r\n");
    }

    /**
     * Sentinel
     * @return string 返却値
     */
    protected function _get()
    {
        $buf = '';
        while($this->_receiving()) {
            $buf .= fgets($this->_socket);
        }
        return rtrim($buf, "\r\n+OK\n");
    }

    /**
     * 分解tcp
     * @param $data string
     * @return array
     */
    protected function _extract($data)
    {
        $tmp = [];
        $return = [];
        $status = 0;
        if(!$data)
            return [];
        $lines = explode("\r\n", $data);
        foreach($lines as $v)
        {
            $prefix = substr($v, 0, 1);
            switch($prefix)
            {
                case '*':
                    if($tmp)
                        $return[] = $tmp;
                    break;
                case '$':
                    break;
                default :
                    if($status){
                        $tmp[$status] = $v;
                        $status = 0;
                    }else{
                        $status = $v;
                    }
                    break;
            }
        }
        $return[] = $tmp;
        return $return;
    }
}