<?php

namespace objects;

class ItemDescriptions extends Base
{
    protected $table_name = "item_descriptions";

    public function __construct($db)
    {
        parent::__construct($db);
    }

    public function getAllDescriptions()
    {
        try {
            $query = "SELECT * FROM {$this->table_name} ORDER BY CASE WHEN is_default = TRUE THEN 0 ELSE 1 END, description ASC";
            $this->getAll($query);
            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            $this->setResult($result);
            return $this;
        }
    }

    public function getDescription($id)
    {
        try {
            $query = "SELECT * FROM {$this->table_name} WHERE id = :id";
            $this->getOne($query, ["id" => $id]);
            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            $this->setResult($result);
            return $this;
        }
    }

    public function addDescription($description)
    {
        try {
            // Verificar si la descripción ya existe
            $query = "SELECT id FROM {$this->table_name} WHERE description = :description";
            $this->getOne($query, ["description" => $description]);
            $result = $this->getResult();

            if ($result->ok && $result->data) {
                // Si ya existe, devolvemos el registro existente
                return $this->getDescription($result->data->id);
            }

            // Si no existe, la agregamos
            $query = "INSERT INTO {$this->table_name} (description, is_default) VALUES (:description, :is_default)";
            $this->add($query, [
                "description" => $description,
                "is_default" => false
            ]);

            if ($this->getResult()->ok) {
                $newId = $this->getResult()->data['newId'];
                return $this->getDescription($newId);
            }

            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            $this->setResult($result);
            return $this;
        }
    }

    public function deleteDescription($id)
    {
        try {
            // No permitir eliminar descripciones predefinidas
            $query = "SELECT is_default FROM {$this->table_name} WHERE id = :id";
            $this->getOne($query, ["id" => $id]);
            $result = $this->getResult();

            if ($result->ok && $result->data && $result->data->is_default) {
                $result = new \stdClass();
                $result->ok = false;
                $result->msg = "No se pueden eliminar descripciones predefinidas";
                $result->data = null;
                $this->setResult($result);
                return $this;
            }

            $query = "DELETE FROM {$this->table_name} WHERE id = :id AND is_default = FALSE";
            $this->delete($query, ["id" => $id]);
            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            $this->setResult($result);
            return $this;
        }
    }
}
