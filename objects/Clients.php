<?php

namespace objects;

class Clients extends Base
{
    protected $table_name = "clients";

    public function getClients($filters = [])
    {
        try {
            $query = "SELECT * FROM {$this->table_name} WHERE 1=1";
            $params = [];

            if (isset($filters['search']) && !empty($filters['search'])) {
                $query .= " AND (business_name LIKE :search OR tax_id LIKE :search)";
                $params['search'] = '%' . $filters['search'] . '%';
            }

            if (isset($filters['status']) && $filters['status'] !== 'todos') {
                $query .= " AND status = :status";
                $params['status'] = $filters['status'];
            }

            $query .= " ORDER BY business_name ASC";

            $this->getAllWithParams($query, $params);
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

    public function getClient($id)
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

    public function createClient($data)
    {
        try {
            $query = "INSERT INTO {$this->table_name} (
                business_name, tax_id, email, phone, address, city,
                state, country, postal_code, contact_person, contact_phone,
                contact_email, credit_limit, payment_terms, tax_condition,
                notes, status, created_at, updated_at
            ) VALUES (
                :business_name, :tax_id, :email, :phone, :address, :city,
                :state, :country, :postal_code, :contact_person, :contact_phone,
                :contact_email, :credit_limit, :payment_terms, :tax_condition,
                :notes, :status, :created_at, :updated_at
            )";

            $params = [
                'business_name' => $data->business_name,
                'tax_id' => $data->tax_id,
                'email' => $data->email ?? null,
                'phone' => $data->phone ?? null,
                'address' => $data->address ?? null,
                'city' => $data->city ?? null,
                'state' => $data->state ?? null,
                'country' => $data->country ?? 'Argentina',
                'postal_code' => $data->postal_code ?? null,
                'contact_person' => $data->contact_person ?? null,
                'contact_phone' => $data->contact_phone ?? null,
                'contact_email' => $data->contact_email ?? null,
                'credit_limit' => $data->credit_limit ?? 0,
                'payment_terms' => $data->payment_terms ?? null,
                'tax_condition' => $data->tax_condition ?? null,
                'notes' => $data->notes ?? null,
                'status' => $data->status ?? 'active',
                'created_at' => $this->now(),
                'updated_at' => $this->now()
            ];

            $this->add($query, $params);
            
            if ($this->getResult()->ok) {
                return $this->getClient($this->getResult()->data['newId']);
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

    public function updateClient($id, $data)
    {
        try {
            $query = "UPDATE {$this->table_name} SET
                business_name = :business_name,
                tax_id = :tax_id,
                email = :email,
                phone = :phone,
                address = :address,
                city = :city,
                state = :state,
                country = :country,
                postal_code = :postal_code,
                contact_person = :contact_person,
                contact_phone = :contact_phone,
                contact_email = :contact_email,
                credit_limit = :credit_limit,
                payment_terms = :payment_terms,
                tax_condition = :tax_condition,
                notes = :notes,
                status = :status,
                updated_at = :updated_at
                WHERE id = :id";

            $params = [
                'id' => $id,
                'business_name' => $data->business_name,
                'tax_id' => $data->tax_id,
                'email' => $data->email ?? null,
                'phone' => $data->phone ?? null,
                'address' => $data->address ?? null,
                'city' => $data->city ?? null,
                'state' => $data->state ?? null,
                'country' => $data->country ?? 'Argentina',
                'postal_code' => $data->postal_code ?? null,
                'contact_person' => $data->contact_person ?? null,
                'contact_phone' => $data->contact_phone ?? null,
                'contact_email' => $data->contact_email ?? null,
                'credit_limit' => $data->credit_limit ?? 0,
                'payment_terms' => $data->payment_terms ?? null,
                'tax_condition' => $data->tax_condition ?? null,
                'notes' => $data->notes ?? null,
                'status' => $data->status ?? 'active',
                'updated_at' => $this->now()
            ];

            $this->update($query, $params);
            
            if ($this->getResult()->ok) {
                return $this->getClient($id);
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

    public function deleteClient($id)
    {
        try {
            $query = "DELETE FROM {$this->table_name} WHERE id = :id";
            $this->delete($query, ['id' => $id]);
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