<?php

namespace objects;

enum Registers
{
    case One;
    case All;
    case Post;
    case Patch;
    case Delete;
};

class Base
{
    protected $conn = null;
    protected $result = null;
    protected $transaction_active = false;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->result = new \stdClass();
        $this->reset();
    }

    private function reset()
    {
        $this->result->ok = true;
        $this->result->msg = "";
        $this->result->data = null;
    }

    private function execSql($query, Registers $resType, array $values = [])
    {
        $this->reset();
        try {
            $stmt = $this->conn->prepare($query);
            if (!empty($values)) {
                foreach ($values as $key => $value) {
                    if (is_bool($value)) {
                        $stmt->bindValue(":" . $key, $value, \PDO::PARAM_BOOL);
                    } elseif (is_null($value)) {
                        $stmt->bindValue(":" . $key, $value, \PDO::PARAM_NULL);
                    } elseif (is_int($value)) {
                        $stmt->bindValue(":" . $key, $value, \PDO::PARAM_INT);
                    } else {
                        $stmt->bindValue(":" . $key, $value, \PDO::PARAM_STR);
                    }
                }
            }
            $stmt->execute();
            switch ($resType) {
                case Registers::One:
                    $this->result->data = $stmt->fetch(\PDO::FETCH_OBJ);
                    break;
                case Registers::All:
                    $this->result->data = $stmt->fetchAll(\PDO::FETCH_OBJ);
                    break;
                case Registers::Post:
                    $this->result->data = ["newId" => $this->conn->lastInsertId()];
                    break;
                case Registers::Patch:
                case Registers::Delete:
                    $this->result->data = $stmt->rowCount();
                    break;
                default:
                    $this->result->data = null;
                    break;
            }
        } catch (\PDOException $e) {
            $this->result->ok = false;
            $this->result->msg = $e->getMessage();
            $this->result->data = null;
            if ($this->transaction_active) {
                $this->rollBack();
            }
        } catch (\Exception $e) {
            $this->result->ok = false;
            $this->result->msg = $e->getMessage();
            $this->result->data = null;
            if ($this->transaction_active) {
                $this->rollBack();
            }
        }
    }

    protected function setResult($result)
    {
        $this->result = $result;
    }

    public function getOne($query, array $values = [])
    {
        $this->execSql($query, Registers::One, $values);
    }

    public function getAll($query)
    {
        $this->execSql($query, Registers::All);
    }

    public function getAllWithParams($query, array $values)
    {
        $this->execSql($query, Registers::All, $values);
    }

    public function add($query, array $values)
    {
        $this->execSql($query, Registers::Post, $values);
    }

    public function update($query, array $values)
    {
        $this->execSql($query, Registers::Patch, $values);
    }

    public function delete($query, array $values)
    {
        $this->execSql($query, Registers::Delete, $values);
    }

    public function getResult()
    {
        return $this->result;
    }

    protected function beginTransaction()
    {
        if (!$this->transaction_active) {
            $this->conn->beginTransaction();
            $this->transaction_active = true;
        }
    }

    protected function commit()
    {
        if ($this->transaction_active) {
            $this->conn->commit();
            $this->transaction_active = false;
        }
    }

    protected function rollBack()
    {
        if ($this->transaction_active) {
            $this->conn->rollBack();
            $this->transaction_active = false;
        }
    }

    protected function inTransaction()
    {
        return $this->transaction_active;
    }

    protected function escape($value)
    {
        if (is_string($value)) {
            return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
        }
        return $value;
    }

    protected function now()
    {
        return date('Y-m-d H:i:s');
    }

    protected function today()
    {
        return date('Y-m-d');
    }
}
