## 安装
```
composer require panwenbin/yii2-on-swoole
```

## 使用
```
$ vendor/bin/yii2onswoole
usage: vendor/bin/yii2onswoole (start|stop|reload) $app $port
```
参数解释
  - start|stop|reload 启动、停止、热重启
  - $app - 是指运行哪个应用，高级版模板有frontend和backend，基础版模板留空
  - $port - 监听的端口，默认8000
 
如果基础版模板要指定端口
```
$ vendor/bin/yii2onswoole start '' 8001
```

## worker_bootstrap.php
第一次运行会复制启动配置文件到根目录 `worker_bootstrap.php`，此文件在worker启动时调用，修改它可以定义YII_DEBUG等，或者替换组件
```php
defined('YII_DEBUG') or define('YII_DEBUG', false); // 如果不定义则是true
defined('YII_ENV') or define('YII_ENV', 'prod'); // 如果不定义则是dev
```

## worker_stop.php
在Yii项目的根目录建立`worker_stop.php`，worker进程正常退出时调用

## 程序编写注意事项
- 使用Yii2推荐的写法编写程序
- 不能使用exit()/die()，它会结束worker进程
- 不要使用php内置session函数，已封装RedisSession
- 不要使用php内置cookie函数，已封装到Response，或者直接操作Swoole的Response
- 不要使用echo/print/print_f/var_dump输出页面内容，他们只会在控制台输出内容
- 不要使用static变量存储内容，它会在下次请求到来时产生干扰