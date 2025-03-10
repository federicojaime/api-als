<?php

namespace objects;

use objects\Base;
use utils\Prepare;

class Invoices extends Base
{
    private $table_name = "invoices";
    private $items_table = "invoice_items";

    public function __construct($db)
    {
        parent::__construct($db);
    }

    // Obtener todas las facturas con filtros opcionales
    public function getInvoices($filters = [])
    {
        $query = "SELECT 
            i.*,
            s.customer as shipment_customer,
            s.origin_address,
            s.destination_address
        FROM $this->table_name i
        LEFT JOIN shipments s ON i.shipment_id = s.id
        WHERE 1=1";

        $values = [];

        if (isset($filters['status']) && $filters['status'] !== 'todos') {
            $query .= " AND i.status = :status";
            $values['status'] = $filters['status'];
        }

        if (isset($filters['search'])) {
            $query .= " AND (i.customer LIKE :search OR i.invoice_number LIKE :search)";
            $values['search'] = "%{$filters['search']}%";
        }

        if (isset($filters['date_from'])) {
            $query .= " AND i.issue_date >= :date_from";
            $values['date_from'] = $filters['date_from'];
        }

        if (isset($filters['date_to'])) {
            $query .= " AND i.issue_date <= :date_to";
            $values['date_to'] = $filters['date_to'];
        }

        $query .= " ORDER BY i.created_at DESC";

        if (!empty($values)) {
            parent::getAllWithParams($query, $values);
        } else {
            parent::getAll($query);
        }

        // Obtener items para cada factura
        $result = parent::getResult();
        if ($result->ok && !empty($result->data)) {
            foreach ($result->data as &$invoice) {
                $invoice->items = $this->getInvoiceItems($invoice->id)->data;
            }
        }

        return $this;
    }

    // Obtener una factura específica
    public function getInvoice($id)
    {
        $query = "SELECT 
            i.*,
            s.customer as shipment_customer,
            s.origin_address,
            s.destination_address
        FROM $this->table_name i
        LEFT JOIN shipments s ON i.shipment_id = s.id
        WHERE i.id = :id";

        parent::getOne($query, ["id" => $id]);

        $result = parent::getResult();
        if ($result->ok && $result->data) {
            $result->data->items = $this->getInvoiceItems($id)->data;
        }

        return $this;
    }

    // Obtener items de una factura
    private function getInvoiceItems($invoice_id)
    {
        $query = "SELECT * FROM $this->items_table WHERE invoice_id = :invoice_id";
        parent::getAll($query, ["invoice_id" => $invoice_id]);
        return parent::getResult();
    }

    // Generar número de factura
    private function generateInvoiceNumber()
    {
        $year = date('Y');
        $query = "SELECT COUNT(*) as count FROM $this->table_name WHERE YEAR(created_at) = :year";
        parent::getOne($query, ["year" => $year]);
        $result = parent::getResult();
        $count = ($result->ok && $result->data) ? $result->data->count + 1 : 1;
        return sprintf('F-%d-%04d', $year, $count);
    }

    // Crear nueva factura
    public function createInvoice($data)
    {
        try {
            // Iniciar transacción
            $this->beginTransaction();

            // Generar número de factura
            $invoice_number = $this->generateInvoiceNumber();

            // Calcular totales
            $subtotal = 0;
            foreach ($data->items as $item) {
                $subtotal += $item->quantity * $item->unit_price;
            }
            $tax = $subtotal * 0.00; // Sin impuestos por ahora
            $total = $subtotal + $tax;

            // Insertar factura principal
            $query = "INSERT INTO $this->table_name SET 
                invoice_number = :invoice_number,
                customer = :customer,
                customer_email = :customer_email,
                customer_phone = :customer_phone,
                customer_address = :customer_address,
                issue_date = :issue_date,
                due_date = :due_date,
                subtotal = :subtotal,
                tax = :tax,
                total = :total,
                status = 'pendiente',
                shipment_id = :shipment_id";

            $values = [
                "invoice_number" => $invoice_number,
                "customer" => $data->customer,
                "customer_email" => $data->customer_email ?? null,
                "customer_phone" => $data->customer_phone ?? null,
                "customer_address" => $data->customer_address ?? null,
                "issue_date" => $data->issue_date,
                "due_date" => $data->due_date,
                "subtotal" => $subtotal,
                "tax" => $tax,
                "total" => $total,
                "shipment_id" => $data->shipment_id ?? null
            ];

            parent::add($query, $values);
            $result = parent::getResult();

            if (!$result->ok) {
                throw new \Exception($result->msg);
            }

            $invoice_id = $result->data['newId'];

            // Insertar items
            foreach ($data->items as $item) {
                $query = "INSERT INTO $this->items_table SET 
                    invoice_id = :invoice_id,
                    description = :description,
                    quantity = :quantity,
                    unit_price = :unit_price,
                    amount = :amount";

                $amount = $item->quantity * $item->unit_price;
                $values = [
                    "invoice_id" => $invoice_id,
                    "description" => $item->description,
                    "quantity" => $item->quantity,
                    "unit_price" => $item->unit_price,
                    "amount" => $amount
                ];

                parent::add($query, $values);
                if (!parent::getResult()->ok) {
                    throw new \Exception("Error al insertar item de factura");
                }
            }

            // Confirmar transacción
            $this->commit();

            // Devolver la factura creada
            return $this->getInvoice($invoice_id);
        } catch (\Exception $e) {
            $this->rollBack();
            $result = parent::getResult();
            $result->ok = false;
            $result->msg = $e->getMessage();
            $result->data = null;
            return $this;
        }
    }

    // Actualizar estado de factura
    public function updateInvoiceStatus($id, $status)
    {
        $valid_statuses = ['pendiente', 'pagada', 'vencida', 'cancelada'];

        if (!in_array($status, $valid_statuses)) {
            $result = parent::getResult();
            $result->ok = false;
            $result->msg = "Estado inválido";
            return $this;
        }

        $query = "UPDATE $this->table_name SET status = :status WHERE id = :id";
        parent::update($query, [
            "id" => $id,
            "status" => $status
        ]);

        return $this;
    }

    // Sincronizar con QuickBooks
    public function syncWithQuickBooks($id, $quickbooks_id)
    {
        $query = "UPDATE $this->table_name SET 
            quickbooks_id = :quickbooks_id 
            WHERE id = :id";

        parent::update($query, [
            "id" => $id,
            "quickbooks_id" => $quickbooks_id
        ]);

        return $this;
    }

    // Eliminar factura
    public function deleteInvoice($id)
    {
        // Los items se eliminarán automáticamente por la restricción ON DELETE CASCADE
        $query = "DELETE FROM $this->table_name WHERE id = :id";
        parent::delete($query, ["id" => $id]);
        return $this;
    }
}
