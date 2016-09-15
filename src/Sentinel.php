<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
namespace think\sentinel;

use think\Log;
use think\sentinel\RedisSentinel\Client;

class Sentinel
{
    protected static $redis_read_handler;
    protected static $redis_writh_handler;
    protected $handler = null;
    protected $options = [
        'servers' => [
            0 => [
                'host' => '127.0.0.1',
                'port' => '26379'
            ],
        ],
        'name' => 'mymaster',
        'password' => '',
        'timeout' => 10,
        'expire' => false,
        'persistent' => false,
        'prefix' => '',
        'serialize' => \Redis::SERIALIZER_PHP,
    ];
    protected static $redis_sentinel;

    /**
     * 为了在单次php请求中复用redis连接，第一次获取的options会被缓存，第二次使用不同的$options，将会无效
     *
     * @param  array $options 缓存参数
     * @access public
     */
    public function __construct($options = [])
    {
        if (!extension_loaded('redis'))
        {
            throw new \BadFunctionCallException('not support: redis');
        }
        $this->options = $options = array_merge($this->options, $options);
        $this->options['func'] = $options['persistent'] ? 'pconnect' : 'connect';
        if (self::$redis_sentinel)
        {
            return true;
        }
        foreach ($this->options['servers'] as $key => $value)
        {
            $server[] = new Client($value['host'], $value['port']);
        }

        //建立哨兵客户端连接
        self::$redis_sentinel = new \think\sentinel\RedisSentinel\Sentinel($this->options['name'], $server);
        if (empty(self::$redis_sentinel->getSentinels()))
        {
            throw new \BadFunctionCallException('Redis Sentinel All Down');
        }
        if (empty(self::$redis_sentinel->getMaster()))
        {
            throw new \BadFunctionCallException('Redis Master All Down');
        }
    }


    protected function master()
    {
        if (self::$redis_writh_handler)
        {
            $this->handler = self::$redis_writh_handler;
        }
        $redis = self::$redis_sentinel->getWriteConn();

        if (null != $this->options['password'])
        {
            $redis->auth($this->options['password']);
        }
        $redis->setOption(\Redis::OPT_SERIALIZER, $this->options['serialize']);
        if (strlen($this->options['prefix']))
        {
            $redis->setOption(\Redis::OPT_PREFIX, $this->options['prefix']);
        }
        $this->handler = self::$redis_writh_handler = $redis;
        return $this;
    }


    protected function slave()
    {
        if (self::$redis_read_handler)
        {
            $this->handler = self::$redis_read_handler;
        }
        $redis = self::$redis_sentinel->getReadConn();

        if (null != $this->options['password'])
        {
            $redis->auth($this->options['password']);
        }
        $redis->setOption(\Redis::OPT_SERIALIZER, $this->options['serialize']);
        if (strlen($this->options['prefix']))
        {
            $redis->setOption(\Redis::OPT_PREFIX, $this->options['prefix']);
        }
        $this->handler = self::$redis_read_handler = $redis;
        return $this;
    }


    /**
     * 判断缓存
     * @access public
     * @param string $name 缓存变量名
     * @return bool
     */
    public function has($name)
    {
        return $this->get($name) ? true : false;
    }

    /**
     * 读取缓存
     *
     * @access public
     * @param  string $name 缓存key
     * @param string $default 默认值
     * @param  bool $master 指定主从节点，可以从主节点获取结果
     * @return mixed
     */
    public function get($name, $default = false, $master = false)
    {
        $this->slave();
        try
        {
            $value = $this->handler->get($name);
        } catch (\RedisException $e)
        {
            unset(self::$redis_read_handler);
            $this->master();
            return $this->get($name, $default);
        } catch (\Exception $e)
        {
            Log::record($e->getMessage(), Log::ERROR);
        }
        return isset($value) ? $value : $default;
    }

    /**
     * 写入缓存
     *
     * @access public
     * @param  string $name 缓存key
     * @param  mixed $value 缓存value
     * @param  integer $expire 过期时间，单位秒
     * @return boolen
     */
    public function set($name, $value, $expire = null)
    {
        $this->master();
        if (is_null($expire))
        {
            $expire = $this->options['expire'];
        }
        try
        {
            if (null === $value)
            {
                return $this->handler->delete($name);
            }
            if (is_int($expire) && $expire)
            {
                $result = $this->handler->setex($name, $expire, $value);
            } else
            {
                $result = $this->handler->set($name, $value);
            }
        } catch (\RedisException $e)
        {
            unset(self::$redis_writh_handler);
            $this->master();
            return $this->set($name, $value, $expire);
        } catch (\Exception $e)
        {
            Log::record($e->getMessage());
        }
        return $result;
    }

    /**
     * 删除缓存
     *
     * @access public
     * @param  string $name 缓存变量名
     * @return boolen
     */
    public function rm($name)
    {
        $this->master();
        return $this->handler->delete($name);
    }

    /**
     * 清除缓存
     *
     * @access public
     * @return boolen
     */
    public function clear()
    {
        $this->master();
        return $this->handler->flushDB();
    }

    /**
     * 返回句柄对象，可执行其它高级方法
     * 需要先执行 $redis->master() 连接到 DB
     *
     * @access public
     * @param  bool $master 指定主从节点，可以从主节点获取结果
     * @return \Redis
     */
    public function handler($master = true)
    {
        if ($master)
        {
            $this->master();
        } else
        {
            $this->slave();
        }
        return $this->handler;
    }

    /**
     * 析构释放连接
     *
     * @access public
     */
    public function __destruct()
    {
        //该方法仅在connect连接时有效
        //当使用pconnect时，连接会被重用，连接的生命周期是fpm进程的生命周期，而非一次php的执行。
        //如果代码中使用pconnect， close的作用仅是使当前php不能再进行redis请求，但无法真正关闭redis长连接，连接在后续请求中仍然会被重用，直至fpm进程生命周期结束。
        try
        {
            if (method_exists($this->handler, "close"))
            {
                $this->handler->close();
            }
        } catch (\Exception $e)
        {
        }
    }
}