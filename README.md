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
在Yii项目的根目录建立`worker_bootstrap.php`，此文件在worker启动时调用，可以定义YII_DEBUG等
```php
defined('YII_DEBUG') or define('YII_DEBUG', false); // 如果不定义则是true
defined('YII_ENV') or define('YII_ENV', 'prod'); // 如果不定义则是dev
```

## worker_stop.php
