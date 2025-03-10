<?php

namespace objects;

class Shipments extends Base
{
    protected $pdo;
    protected $table_name = "shipments";
    protected $items_table = "shipment_items";
    protected $documents_table = "shipment_documents";

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        parent::__construct($pdo);
    }

    public function getShipment($id)
    {
        try {
            $id = trim($id);
            if (!is_numeric($id)) {
                throw new \Exception("ID de envío inválido");
            }

            $query = "SELECT 
                s.*,
                u.firstname as driver_firstname,
                u.lastname as driver_lastname
            FROM {$this->table_name} s
            LEFT JOIN users u ON s.driver_id = u.id
            WHERE s.id = :id";

            parent::getOne($query, ["id" => $id]);
            $result = parent::getResult();

            if (!$result->ok || !$result->data) {
                throw new \Exception("Envío no encontrado");
            }

            $shipment = $result->data;

            // Obtener items y documentos
            $itemsResult = $this->getShipmentItems($id);
            $documentsResult = $this->getShipmentDocuments($id);

            $shipment->items = (isset($itemsResult->data) && $itemsResult->data) ? $itemsResult->data : [];
            $shipment->documents = (isset($documentsResult->data) && $documentsResult->data) ? $documentsResult->data : [];

            $result->data = $shipment;
            parent::setResult($result);

            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            parent::setResult($result);
            return $this;
        }
    }

    public function getShipments($filters = [])
    {
        try {
            $query = "SELECT 
                s.*,
                u.firstname as driver_firstname,
                u.lastname as driver_lastname
            FROM {$this->table_name} s
            LEFT JOIN users u ON s.driver_id = u.id
            WHERE 1=1";

            $params = [];

            if (isset($filters['status']) && $filters['status'] !== 'todos' && $filters['status'] !== '') {
                $query .= " AND s.status = :status";
                $params['status'] = $filters['status'];
            }

            if (isset($filters['driver_id']) && !empty($filters['driver_id'])) {
                $query .= " AND s.driver_id = :driver_id";
                $params['driver_id'] = $filters['driver_id'];
            }

            if (isset($filters['search']) && !empty($filters['search'])) {
                $query .= " AND (s.customer LIKE :search OR s.destination_address LIKE :search)";
                $params['search'] = '%' . $filters['search'] . '%';
            }

            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $query .= " AND s.created_at >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to']) && !empty($filters['date_to'])) {
                $query .= " AND s.created_at <= :date_to";
                $params['date_to'] = $filters['date_to'];
            }

            $query .= " ORDER BY s.created_at DESC";

            $stmt = $this->pdo->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue(":" . $key, $value);
            }
            $stmt->execute();

            $shipments = $stmt->fetchAll(\PDO::FETCH_OBJ);

            if ($shipments) {
                foreach ($shipments as &$shipment) {
                    $itemsQuery = "SELECT * FROM {$this->items_table} WHERE shipment_id = :shipment_id";
                    $itemsStmt = $this->pdo->prepare($itemsQuery);
                    $itemsStmt->bindValue(':shipment_id', $shipment->id);
                    $itemsStmt->execute();
                    $shipment->items = $itemsStmt->fetchAll(\PDO::FETCH_OBJ);

                    $docsQuery = "SELECT id, name, document_type, file_content, created_at 
                                FROM {$this->documents_table} 
                                WHERE shipment_id = :shipment_id";
                    $docsStmt = $this->pdo->prepare($docsQuery);
                    $docsStmt->bindValue(':shipment_id', $shipment->id);
                    $docsStmt->execute();
                    $shipment->documents = $docsStmt->fetchAll(\PDO::FETCH_OBJ);
                }
            }

            $result = new \stdClass();
            $result->ok = true;
            $result->msg = '';
            $result->data = $shipments ?: [];

            parent::setResult($result);
            return $this;
        } catch (\Exception $e) {
            error_log("Error en getShipments: " . $e->getMessage());
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            parent::setResult($result);
            return $this;
        }
    }

    public function createShipment($data)
    {
        try {
            $this->pdo->beginTransaction();

            $query = "INSERT INTO {$this->table_name} 
                      (customer, origin_address, destination_address, status, shipping_cost, driver_id, created_at, updated_at)
                      VALUES (:customer, :origin_address, :destination_address, :status, :shipping_cost, :driver_id, NOW(), NOW())";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                'customer' => $data->customer,
                'origin_address' => $data->origin_address,
                'destination_address' => $data->destination_address,
                'status' => $data->status ?? 'pendiente',
                'shipping_cost' => $data->shipping_cost,
                'driver_id' => $data->driver_id,
            ]);

            $shipmentId = $this->pdo->lastInsertId();

            if (isset($data->items) && is_array($data->items)) {
                foreach ($data->items as $item) {
                    if (!isset($item['descripcion']) && !isset($item['description'])) continue;
                    if (!isset($item['cantidad']) && !isset($item['quantity'])) continue;
                    if (!isset($item['peso']) && !isset($item['weight'])) continue;
                    if (!isset($item['valor']) && !isset($item['value'])) continue;

                    $queryItem = "INSERT INTO {$this->items_table} 
                                  (shipment_id, description, quantity, weight, value)
                                  VALUES (:shipment_id, :description, :quantity, :weight, :value)";

                    $stmtItem = $this->pdo->prepare($queryItem);
                    $stmtItem->execute([
                        'shipment_id' => $shipmentId,
                        'description' => $item['descripcion'] ?? $item['description'],
                        'quantity' => $item['cantidad'] ?? $item['quantity'],
                        'weight' => $item['peso'] ?? $item['weight'],
                        'value' => $item['valor'] ?? $item['value']
                    ]);
                }
            }

            if (isset($data->documents) && is_array($data->documents)) {
                foreach ($data->documents as $doc) {
                    if (!isset($doc['name']) || !isset($doc['file_content'])) continue;

                    $queryDoc = "INSERT INTO {$this->documents_table}
                                (shipment_id, name, file_content, document_type, created_at)
                                VALUES (:shipment_id, :name, :file_content, :document_type, NOW())";

                    $stmtDoc = $this->pdo->prepare($queryDoc);
                    $stmtDoc->execute([
                        'shipment_id' => $shipmentId,
                        'name' => $doc['name'],
                        'file_content' => $doc['file_content'],
                        'document_type' => $doc['document_type'] ?? 'shipment_doc'
                    ]);
                }
            }

            $this->pdo->commit();
            return $this->getShipment($shipmentId);
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            $this->setResult($result);
            return $this;
        }
    }

    public function updateShipment($data)
    {
        try {
            $this->pdo->beginTransaction();

            // Actualizar información principal
            $query = "UPDATE {$this->table_name} SET 
                      customer = :customer,
                      origin_address = :origin_address,
                      destination_address = :destination_address,
                      status = :status,
                      shipping_cost = :shipping_cost,
                      driver_id = :driver_id,
                      updated_at = NOW()
                      WHERE id = :id";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                'customer' => $data->customer,
                'origin_address' => $data->origin_address,
                'destination_address' => $data->destination_address,
                'status' => $data->status,
                'shipping_cost' => $data->shipping_cost,
                'driver_id' => $data->driver_id,
                'id' => $data->id
            ]);

            // Actualizar items
            if (isset($data->items)) {
                // Eliminar items existentes
                $deleteItems = "DELETE FROM {$this->items_table} WHERE shipment_id = :shipment_id";
                $stmtDelete = $this->pdo->prepare($deleteItems);
                $stmtDelete->execute(['shipment_id' => $data->id]);

                // Insertar nuevos items
                foreach ($data->items as $item) {
                    $queryItem = "INSERT INTO {$this->items_table} 
                                (shipment_id, description, quantity, weight, value)
                                VALUES (:shipment_id, :description, :quantity, :weight, :value)";

                    $stmtItem = $this->pdo->prepare($queryItem);
                    $stmtItem->execute([
                        'shipment_id' => $data->id,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'weight' => $item['weight'],
                        'value' => $item['value']
                    ]);
                }
            }

            // Agregar nuevos documentos si existen
            if (isset($data->new_documents) && is_array($data->new_documents)) {
                foreach ($data->new_documents as $doc) {
                    $queryDoc = "INSERT INTO {$this->documents_table}
                                (shipment_id, name, file_content, document_type, created_at)
                                VALUES (:shipment_id, :name, :file_content, :document_type, NOW())";

                    $stmtDoc = $this->pdo->prepare($queryDoc);
                    $stmtDoc->execute([
                        'shipment_id' => $data->id,
                        'name' => $doc['name'],
                        'file_content' => $doc['file_content'],
                        'document_type' => $doc['document_type'] ?? 'shipment_doc'
                    ]);
                }
            }

            $this->pdo->commit();
            return $this->getShipment($data->id);
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            $this->setResult($result);
            return $this;
        }
    }

    public function updateShipmentStatus($id, $status)
    {
        try {
            $query = "UPDATE {$this->table_name} SET status = :status, updated_at = NOW() WHERE id = :id";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                'status' => $status,
                'id' => $id
            ]);

            $result = new \stdClass();
            $result->ok = true;
            $result->msg = "Estado actualizado correctamente";
            $result->data = ['id' => $id, 'status' => $status];
            parent::setResult($result);
            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            parent::setResult($result);
            return $this;
        }
    }

    public function getShipmentItems($shipment_id)
    {
        try {
            $query = "SELECT * FROM {$this->items_table} WHERE shipment_id = :shipment_id";
            $this->getAllWithParams($query, ["shipment_id" => $shipment_id]);
            return $this->getResult();
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            $this->setResult($result);
            return $this->getResult();
        }
    }

    public function getShipmentDocuments($shipment_id)
    {
        try {
            $query = "SELECT * FROM {$this->documents_table} WHERE shipment_id = :shipment_id";
            $this->getAllWithParams($query, ["shipment_id" => $shipment_id]);
            return $this->getResult();
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            parent::setResult($result);
            return parent::getResult();
        }
    }

    public function getDocument($documentId)
    {
        try {
            $query = "SELECT * FROM {$this->documents_table} WHERE id = :id";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute(['id' => $documentId]);
            $doc = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$doc) {
                throw new \Exception("Documento no encontrado");
            }

            $result = new \stdClass();
            $result->ok = true;
            $result->msg = "";
            $result->data = $doc;
            parent::setResult($result);
            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            parent::setResult($result);
            return $this;
        }
    }

    public function deleteDocument($documentId)
    {
        try {
            $query = "DELETE FROM {$this->documents_table} WHERE id = :id";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute(['id' => $documentId]);

            $result = new \stdClass();
            $result->ok = true;
            $result->msg = "Documento eliminado correctamente";
            $result->data = null;
            parent::setResult($result);
            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            parent::setResult($result);
            return $this;
        }
    }

    public function uploadPOD($shipmentId, $document)
    {
        try {
            $query = "INSERT INTO {$this->documents_table} 
                      (shipment_id, name, file_content, document_type, created_at)
                      VALUES (:shipment_id, :name, :file_content, :document_type, NOW())";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                'shipment_id' => $shipmentId,
                'name' => $document->name,
                'file_content' => $document->file_content,
                'document_type' => $document->document_type,
            ]);

            $documentId = $this->pdo->lastInsertId();

            $result = new \stdClass();
            $result->ok = true;
            $result->msg = "POD subido correctamente";
            $result->data = [
                'id' => $documentId,
                'name' => $document->name,
                'file_content' => $document->file_content,
                'document_type' => $document->document_type
            ];
            parent::setResult($result);
            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            parent::setResult($result);
            return $this;
        }
    }

    public function addDocument($shipmentId, $document)
    {
        try {
            $query = "INSERT INTO {$this->documents_table}
                      (shipment_id, name, file_content, document_type, created_at)
                      VALUES (:shipment_id, :name, :file_content, :document_type, NOW())";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                'shipment_id' => $shipmentId,
                'name' => $document->name,
                'file_content' => $document->file_content,
                'document_type' => $document->document_type ?? 'shipment_doc'
            ]);

            $documentId = $this->pdo->lastInsertId();

            // Obtener el documento recién creado
            $query = "SELECT * FROM {$this->documents_table} WHERE id = :id";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute(['id' => $documentId]);
            $newDoc = $stmt->fetch(\PDO::FETCH_OBJ);

            $result = new \stdClass();
            $result->ok = true;
            $result->msg = "Documento agregado correctamente";
            $result->data = $newDoc;
            parent::setResult($result);
            return $this;
        } catch (\Exception $e) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            parent::setResult($result);
            return $this;
        }
    }

    public function deleteShipment($id)
    {
        try {
            $this->pdo->beginTransaction();

            // Eliminar items
            $queryItems = "DELETE FROM {$this->items_table} WHERE shipment_id = :id";
            $stmtItems = $this->pdo->prepare($queryItems);
            $stmtItems->execute(['id' => $id]);

            // Eliminar documentos
            $queryDocs = "DELETE FROM {$this->documents_table} WHERE shipment_id = :id";
            $stmtDocs = $this->pdo->prepare($queryDocs);
            $stmtDocs->execute(['id' => $id]);

            // Eliminar el envío
            $queryShipment = "DELETE FROM {$this->table_name} WHERE id = :id";
            $stmtShipment = $this->pdo->prepare($queryShipment);
            $stmtShipment->execute(['id' => $id]);

            $this->pdo->commit();

            $result = new \stdClass();
            $result->ok = true;
            $result->msg = "Envío eliminado correctamente";
            $result->data = null;
            parent::setResult($result);
            return $this;
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $result = new \stdClass();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            parent::setResult($result);
            return $this;
        }
    }
}
