<?php
/**
 * @author Pan Wenbin <panwenbin@gmail.com>
 */

namespace onswoole\yii2;


class Logger extends \yii\log\Logger
{
    public function init()
    {
        \Yii::setLogger($this);
    }
}