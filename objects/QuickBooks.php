<?php

namespace objects;

use objects\Base;
use utils\Prepare;

class QuickBooks extends Base
{
    private $table_name = "quickbooks_config";

    public function __construct($db)
    {
        parent::__construct($db);
    }

    // Obtener configuración actual
    public function getConfig()
    {
        $query = "SELECT * FROM $this->table_name ORDER BY id DESC LIMIT 1";
        parent::getOne($query);
        return $this;
    }

    // Guardar o actualizar tokens
    public function saveTokens($tokens)
    {
        try {
            $current = $this->getConfig()->getResult();

            if ($current->ok && $current->data) {
                // Actualizar existente
                $query = "UPDATE $this->table_name SET 
                    access_token = :access_token,
                    refresh_token = :refresh_token,
                    realm_id = :realm_id,
                    token_expires_at = :token_expires_at,
                    updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id";

                $values = [
                    "id" => $current->data->id,
                    "access_token" => $tokens->access_token,
                    "refresh_token" => $tokens->refresh_token,
                    "realm_id" => $tokens->realm_id,
                    "token_expires_at" => date('Y-m-d H:i:s', time() + $tokens->expires_in)
                ];

                parent::update($query, $values);
            } else {
                // Crear nuevo
                $query = "INSERT INTO $this->table_name SET 
                    access_token = :access_token,
                    refresh_token = :refresh_token,
                    realm_id = :realm_id,
                    token_expires_at = :token_expires_at";

                $values = [
                    "access_token" => $tokens->access_token,
                    "refresh_token" => $tokens->refresh_token,
                    "realm_id" => $tokens->realm_id,
                    "token_expires_at" => date('Y-m-d H:i:s', time() + $tokens->expires_in)
                ];

                parent::add($query, $values);
            }

            return $this;
        } catch (\Exception $e) {
            $result = parent::getResult();
            $result->ok = false;
            $result->msg = $e->getMessage();
            return $this;
        }
    }

    // Verificar si los tokens están expirados
    public function areTokensExpired()
    {
        $config = $this->getConfig()->getResult();
        if (!$config->ok || !$config->data) {
            return true;
        }

        $expires_at = strtotime($config->data->token_expires_at);
        return time() >= $expires_at;
    }

    // Eliminar configuración
    public function deleteConfig()
    {
        $query = "DELETE FROM $this->table_name";
        parent::delete($query, []);
        return $this;
    }

    // Obtener cliente para las APIs de QuickBooks
    public function getClient()
    {
        $config = $this->getConfig()->getResult();
        if (!$config->ok || !$config->data) {
            throw new \Exception("No hay configuración de QuickBooks");
        }

        return [
            'access_token' => $config->data->access_token,
            'refresh_token' => $config->data->refresh_token,
            'realm_id' => $config->data->realm_id,
            'expires_at' => strtotime($config->data->token_expires_at)
        ];
    }
}
