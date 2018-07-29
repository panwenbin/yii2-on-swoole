<?php
/**
 * @author Pan Wenbin <panwenbin@gmail.com>
 */

namespace onswoole\yii2;

use Yii;
use yii\db\Command;
use yii\db\Exception;
use yii\db\DataReader;

class DbCommand extends Command
{
    /**
     * @param \Exception $e
     * @return bool
     */
    protected function isConnectionError(\Exception $e)
    {
        if ($e instanceof \PDOException) {
            $errorInfo = $this->pdoStatement->errorInfo();
            if ($errorInfo[1] == 70100 || $errorInfo[1] == 2006) {
                return true;
            }
        }
        $message = $e->getMessage();
        if (strpos($message, 'Error while sending QUERY packet. PID=') !== false) {
            return true;
        }
        return false;
    }

    /**
     * @param null $forRead
     * @param string $rawSql
     */
    protected function reconnect($forRead = null, $rawSql = '')
    {
        $this->db->close();
        $this->db->open();

        if ($this->db->getTransaction()) {
            // master is in a transaction. use the same connection.
            $forRead = false;
        }
        if ($forRead || $forRead === null && $this->db->getSchema()->isReadQuery($sql)) {
            $pdo = $this->db->getSlavePdo();
        } else {
            $pdo = $this->db->getMasterPdo();
        }

        try {
            $this->pdoStatement = $pdo->prepare($rawSql ?: $this->getRawSql());
        } catch (\Exception $e) {
            $message = $e->getMessage() . "\nFailed to prepare SQL: $sql";
            $errorInfo = $e instanceof \PDOException ? $e->errorInfo : null;
            throw new Exception($message, $errorInfo, (int)$e->getCode(), $e);
        }
    }

    /**
     * @param string $method
     * @param null $fetchMode
     * @return mixed
     * @throws \yii\db\Exception
     * @throws \Exception
     */
    protected function queryInternal($method, $fetchMode = null)
    {
        try {
            return parent::queryInternal($method, $fetchMode);
        } catch (\Exception $e) {
            if ($this->isConnectionError($e)) {
                $this->reconnect(true);
                return parent::queryInternal($method, $fetchMode);
            }
            throw $e;
        }
    }

    /**
     * @return int
     */
    public function execute()
    {
        $sql = $this->getSql();
        list($profile, $rawSql) = $this->logQuery(__METHOD__);

        if ($sql == '') {
            return 0;
        }

        $this->prepare(false);

        try {
            $profile and Yii::beginProfile($rawSql, __METHOD__);

            $this->internalExecute($rawSql);
            $n = $this->pdoStatement->rowCount();

            $profile and Yii::endProfile($rawSql, __METHOD__);

            $this->refreshTableSchema();

            return $n;
        } catch (Exception $e) {
            if ($this->isConnectionError($e)) {
                $this->reconnect(false, $rawSql);
                return $this->internalExecute($rawSql);
            }
            $profile and Yii::endProfile($rawSql, __METHOD__);
            throw $e;
        }
    }

}