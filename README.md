ThinkPHP 5.0 Redis Sentinel驱动
=============================

首先安装官方的php-redis扩展：

http://pecl.php.net/package/redis

然后，配置应用的配置文件`config.php`的`cache['type']`参数为：

~~~
'type'  =>  '\think\sentinel\Sentinel',
~~~

并增加一下参数
cache['server'](是个数组,host是地址,port是端口号):
~~~
'servers'=>[
    0=>[
        'host'=>'127.0.0.1',
         'port'=>'26379'
    ],
    1=>[
        'host'=>'127.0.0.1',
        'port'=>'26380'
    ]
],
~~~

cache['name']:是哨兵的名字
~~~
'name'  =>  'mymaster',
~~~


即可正常使用Sentinel，例如：
~~~
Cache::get('test');
Cache::get('test','test');
~~~