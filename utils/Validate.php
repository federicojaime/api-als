<?php
namespace utils;

class Validate {
    private $conn = null;
    private $errors = [];

    public function __construct($db = null) {
        $this->conn = $db;
    }

    public function validar($fields, $verificaciones) {
        $valores = (object) $fields;
        $this->errors = [];

        foreach($verificaciones as $key => $rules) {
            if(!property_exists($valores, $key)) {
                $this->errors[] = "{$key} es un valor requerido.";
            } else {
                $v = $valores->{$key};
                $last_type = null;
                foreach($rules as $rule => $value) {
                    switch($rule) {
                        case "type":
                            $last_type = $value;
                            switch($value) {
                                case "number":
                                    if(!is_numeric($v)) {
                                        $this->errors[] = "{$key} [$v] no es un número válido.";
                                    }
                                    break;
                                case "string":
                                    if(!is_string($v)) {
                                        $this->errors[] = "{$key} [$v] no es una cadena de caracteres válida.";
                                    }
                                    break;
                                case "date":
                                    if(strtotime($v) === false) {
                                        $this->errors[] = "{$key} [$v] no es una fecha válida.";
                                    }
                                    break;
                                case "time":
                                    $formato = 'H:i:s';
                                    $horario_obj = \DateTime::createFromFormat($formato, $v);
                                    if(!($horario_obj && $horario_obj->format($formato) === $v)) {
                                        $this->errors[] = "{$key} [$v] no es un horario válido.";
                                    }
                                    break;
                                case "array":
                                    if(!is_array($v)) {
                                        $this->errors[] = "{$key} no es un array válido.";
                                    }
                                    break;
                                case "boolean":
                                    if(!is_bool($v)) {
                                        $this->errors[] = "{$key} [$v] no es un valor booleano válido.";
                                    }
                                    break;
                                default:
                                    $this->errors[] = "{$value} no es un 'type' válido.";
                                    break;
                            }
                            break;
                        case "min":
                            switch($last_type) {
                                case "number":
                                    if($v < $value) {
                                        $this->errors[] = "{$key} [$v] no debe ser menor a {$value}.";
                                    }
                                    break;
                                case "string":
                                    if(strlen($v) < $value) {
                                        $this->errors[] = "{$key} debe tener al menos {$value} caracteres.";
                                    }
                                    break;
                                case "date":
                                    $fecha1_obj = new \DateTime($v);
                                    $fecha2_obj = new \DateTime($value);
                                    if($fecha1_obj->diff($fecha2_obj)->format('%R') === '-') {
                                        $this->errors[] = "{$key} [$v] debe ser posterior a {$value}.";
                                    }
                                    break;
                                case "array":
                                    if(count($v) < $value) {
                                        $this->errors[] = "{$key} debe tener al menos {$value} elementos.";
                                    }
                                    break;
                            }
                            break;
                        case "max":
                            switch($last_type) {
                                case "number":
                                    if($v > $value) {
                                        $this->errors[] = "{$key} [$v] no debe ser mayor a {$value}.";
                                    }
                                    break;
                                case "string":
                                    if(strlen($v) > $value) {
                                        $this->errors[] = "{$key} debe tener como máximo {$value} caracteres.";
                                    }
                                    break;
                                case "date":
                                    $fecha1_obj = new \DateTime($v);
                                    $fecha2_obj = new \DateTime($value);
                                    if($fecha1_obj->diff($fecha2_obj)->format('%R') === '+') {
                                        $this->errors[] = "{$key} [$v] debe ser anterior a {$value}.";
                                    }
                                    break;
                                case "array":
                                    if(count($v) > $value) {
                                        $this->errors[] = "{$key} debe tener como máximo {$value} elementos.";
                                    }
                                    break;
                            }
                            break;
                        case "values":
                            if(!in_array($v, $value)) {
                                $this->errors[] = "{$key} [$v] debe ser uno de los siguientes valores: " . implode(", ", $value);
                            }
                            break;
                        case "isValidMail":
                            if($last_type === "string" && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                                $this->errors[] = "{$key} [$v] no es una dirección de correo válida.";
                            }
                            break;
                        case "unique":
                            if(!is_null($this->conn)) {
                                try {
                                    $query = "SELECT id FROM {$value} WHERE {$key} = :{$key}";
                                    if(isset($fields['id'])) {
                                        $query .= " AND id != :id";
                                    }
                                    $stmt = $this->conn->prepare($query);
                                    $stmt->bindParam(":{$key}", $v);
                                    if(isset($fields['id'])) {
                                        $stmt->bindParam(":id", $fields['id']);
                                    }
                                    if($stmt->execute()) {
                                        if($stmt->rowCount() > 0) {
                                            $this->errors[] = "El valor [$v] ya existe en {$value}.";
                                        }
                                    }
                                } catch (\PDOException $e) {
                                    $this->errors[] = "Error al verificar unicidad: " . $e->getMessage();
                                }
                            }
                            break;
                        case "exist":
                            if(!is_null($this->conn)) {
                                try {
                                    $query = "SELECT id FROM {$value} WHERE id = :{$key}";
                                    $stmt = $this->conn->prepare($query);
                                    $stmt->bindParam(":{$key}", $v);
                                    if($stmt->execute()) {
                                        if($stmt->rowCount() === 0) {
                                            $this->errors[] = "El {$key} [$v] no existe en {$value}.";
                                        }
                                    }
                                } catch (\PDOException $e) {
                                    $this->errors[] = "Error al verificar existencia: " . $e->getMessage();
                                }
                            }
                            break;
                    }
                }
            }
        }
    }

    public function hasErrors() {
        return count($this->errors) > 0;
    }

    public function getErrors() {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = "Hay errores en los datos suministrados";
        $resp->data = null;
        $resp->errores = $this->errors;
        return $resp;
    }
}