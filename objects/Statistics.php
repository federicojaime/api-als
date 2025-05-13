<?php

namespace objects;

class Statistics extends Base
{
    public function __construct($db)
    {
        parent::__construct($db);
    }

    public function getGeneralStatistics()
    {
        try {
            $result = new \stdClass();

            // Obtener cantidad de envíos por estado
            $shipmentsByStatusQuery = "SELECT status, COUNT(*) as count FROM shipments GROUP BY status";
            $this->getAllWithParams($shipmentsByStatusQuery, []);
            $result->shipmentsByStatus = $this->getResult()->data ?? [];

            // Obtener cantidad de facturas por estado
            $invoicesByStatusQuery = "SELECT status, COUNT(*) as count FROM invoices GROUP BY status";
            $this->getAllWithParams($invoicesByStatusQuery, []);
            $result->invoicesByStatus = $this->getResult()->data ?? [];

            // Obtener cantidad de clientes activos/inactivos
            $clientsByStatusQuery = "SELECT status, COUNT(*) as count FROM clients GROUP BY status";
            $this->getAllWithParams($clientsByStatusQuery, []);
            $result->clientsByStatus = $this->getResult()->data ?? [];

            // Obtener total de usuarios por rol
            $usersByRoleQuery = "SELECT role, COUNT(*) as count FROM users WHERE active = true GROUP BY role";
            $this->getAllWithParams($usersByRoleQuery, []);
            $result->usersByRole = $this->getResult()->data ?? [];

            // Estadísticas financieras
            $financialStatsQuery = "SELECT 
                SUM(total) as total_invoiced, 
                SUM(CASE WHEN status = 'pagada' THEN total ELSE 0 END) as total_collected,
                SUM(CASE WHEN status = 'pendiente' THEN total ELSE 0 END) as total_pending
                FROM invoices";
            $this->getOne($financialStatsQuery, []);
            $result->financialStats = $this->getResult()->data ?? null;

            $resp = new \stdClass();
            $resp->ok = true;
            $resp->msg = "Estadísticas generales obtenidas correctamente";
            $resp->data = $result;

            $this->setResult($resp);
            return $this;
        } catch (\Exception $e) {
            $resp = new \stdClass();
            $resp->ok = false;
            $resp->msg = $e->getMessage();
            $resp->data = null;
            $this->setResult($resp);
            return $this;
        }
    }

    public function getShipmentStatistics($filters = [])
    {
        try {
            $result = new \stdClass();
            $whereClause = "WHERE 1=1";
            $params = [];

            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $whereClause .= " AND created_at >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to']) && !empty($filters['date_to'])) {
                $whereClause .= " AND created_at <= :date_to";
                $params['date_to'] = $filters['date_to'];
            }

            if (isset($filters['client_id']) && !empty($filters['client_id'])) {
                $whereClause .= " AND client_id = :client_id";
                $params['client_id'] = $filters['client_id'];
            }

            if (isset($filters['driver_id']) && !empty($filters['driver_id'])) {
                $whereClause .= " AND driver_id = :driver_id";
                $params['driver_id'] = $filters['driver_id'];
            }

            // Envíos por mes
            $shipmentsByMonthQuery = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month, 
                COUNT(*) as count 
                FROM shipments 
                $whereClause 
                GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
                ORDER BY month";
            $this->getAllWithParams($shipmentsByMonthQuery, $params);
            $result->shipmentsByMonth = $this->getResult()->data ?? [];

            // Envíos por estado
            $shipmentsByStatusQuery = "SELECT 
                status, 
                COUNT(*) as count 
                FROM shipments 
                $whereClause 
                GROUP BY status";
            $this->getAllWithParams($shipmentsByStatusQuery, $params);
            $result->shipmentsByStatus = $this->getResult()->data ?? [];

            // Envíos por conductor
            $shipmentsByDriverQuery = "SELECT 
                s.driver_id, 
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                COUNT(*) as count 
                FROM shipments s
                LEFT JOIN users u ON s.driver_id = u.id
                $whereClause 
                GROUP BY s.driver_id, driver_name
                ORDER BY count DESC";
            $this->getAllWithParams($shipmentsByDriverQuery, $params);
            $result->shipmentsByDriver = $this->getResult()->data ?? [];

            // Envíos por cliente
            $shipmentsByClientQuery = "SELECT 
                s.client_id, 
                c.business_name as client_name,
                COUNT(*) as count 
                FROM shipments s
                LEFT JOIN clients c ON s.client_id = c.id
                $whereClause 
                GROUP BY s.client_id, client_name
                ORDER BY count DESC";
            $this->getAllWithParams($shipmentsByClientQuery, $params);
            $result->shipmentsByClient = $this->getResult()->data ?? [];

            // Ingresos totales por envíos
            $totalRevenueQuery = "SELECT SUM(shipping_cost) as total_revenue FROM shipments $whereClause";
            $this->getOne($totalRevenueQuery, $params);
            $result->totalRevenue = $this->getResult()->data ? $this->getResult()->data->total_revenue : 0;

            $resp = new \stdClass();
            $resp->ok = true;
            $resp->msg = "Estadísticas de envíos obtenidas correctamente";
            $resp->data = $result;

            $this->setResult($resp);
            return $this;
        } catch (\Exception $e) {
            $resp = new \stdClass();
            $resp->ok = false;
            $resp->msg = $e->getMessage();
            $resp->data = null;
            $this->setResult($resp);
            return $this;
        }
    }

    public function getInvoiceStatistics($filters = [])
    {
        try {
            $result = new \stdClass();
            $whereClause = "WHERE 1=1";
            $params = [];

            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $whereClause .= " AND issue_date >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to']) && !empty($filters['date_to'])) {
                $whereClause .= " AND issue_date <= :date_to";
                $params['date_to'] = $filters['date_to'];
            }

            if (isset($filters['status']) && !empty($filters['status']) && $filters['status'] !== 'todos') {
                $whereClause .= " AND status = :status";
                $params['status'] = $filters['status'];
            }

            // Facturas por mes
            $invoicesByMonthQuery = "SELECT 
                DATE_FORMAT(issue_date, '%Y-%m') as month, 
                COUNT(*) as count,
                SUM(total) as total_amount
                FROM invoices 
                $whereClause 
                GROUP BY DATE_FORMAT(issue_date, '%Y-%m') 
                ORDER BY month";
            $this->getAllWithParams($invoicesByMonthQuery, $params);
            $result->invoicesByMonth = $this->getResult()->data ?? [];

            // Facturas por estado
            $invoicesByStatusQuery = "SELECT 
                status, 
                COUNT(*) as count,
                SUM(total) as total_amount
                FROM invoices 
                $whereClause 
                GROUP BY status";
            $this->getAllWithParams($invoicesByStatusQuery, $params);
            $result->invoicesByStatus = $this->getResult()->data ?? [];

            // Facturas por cliente
            $invoicesByClientQuery = "SELECT 
                customer, 
                COUNT(*) as count,
                SUM(total) as total_amount 
                FROM invoices 
                $whereClause 
                GROUP BY customer
                ORDER BY total_amount DESC";
            $this->getAllWithParams($invoicesByClientQuery, $params);
            $result->invoicesByClient = $this->getResult()->data ?? [];

            // Montos promedio
            $averageAmountsQuery = "SELECT 
                AVG(total) as average_total,
                MIN(total) as min_total,
                MAX(total) as max_total
                FROM invoices 
                $whereClause";
            $this->getOne($averageAmountsQuery, $params);
            $result->averageAmounts = $this->getResult()->data ?? null;

            $resp = new \stdClass();
            $resp->ok = true;
            $resp->msg = "Estadísticas de facturación obtenidas correctamente";
            $resp->data = $result;

            $this->setResult($resp);
            return $this;
        } catch (\Exception $e) {
            $resp = new \stdClass();
            $resp->ok = false;
            $resp->msg = $e->getMessage();
            $resp->data = null;
            $this->setResult($resp);
            return $this;
        }
    }

    public function getClientStatistics($filters = [])
    {
        try {
            $result = new \stdClass();

            // Clientes más activos (por número de envíos)
            $activeClientsQuery = "SELECT 
                c.id,
                c.business_name,
                COUNT(s.id) as shipment_count
                FROM clients c
                LEFT JOIN shipments s ON c.id = s.client_id
                GROUP BY c.id, c.business_name
                ORDER BY shipment_count DESC
                LIMIT 10";
            $this->getAllWithParams($activeClientsQuery, []);
            $result->activeClients = $this->getResult()->data ?? [];

            // Clientes por volumen de facturación
            $clientsByBillingQuery = "SELECT 
                i.customer,
                SUM(i.total) as total_billed,
                COUNT(i.id) as invoice_count
                FROM invoices i
                GROUP BY i.customer
                ORDER BY total_billed DESC
                LIMIT 10";
            $this->getAllWithParams($clientsByBillingQuery, []);
            $result->clientsByBilling = $this->getResult()->data ?? [];

            // Clientes por estado
            $clientsByStatusQuery = "SELECT 
                status,
                COUNT(*) as count
                FROM clients
                GROUP BY status";
            $this->getAllWithParams($clientsByStatusQuery, []);
            $result->clientsByStatus = $this->getResult()->data ?? [];

            // Clientes con pagos pendientes
            $clientsWithPendingQuery = "SELECT 
                i.customer,
                SUM(CASE WHEN i.status = 'pendiente' THEN i.total ELSE 0 END) as pending_amount,
                COUNT(CASE WHEN i.status = 'pendiente' THEN i.id END) as pending_count
                FROM invoices i
                GROUP BY i.customer
                HAVING pending_amount > 0
                ORDER BY pending_amount DESC";
            $this->getAllWithParams($clientsWithPendingQuery, []);
            $result->clientsWithPending = $this->getResult()->data ?? [];

            $resp = new \stdClass();
            $resp->ok = true;
            $resp->msg = "Estadísticas de clientes obtenidas correctamente";
            $resp->data = $result;

            $this->setResult($resp);
            return $this;
        } catch (\Exception $e) {
            $resp = new \stdClass();
            $resp->ok = false;
            $resp->msg = $e->getMessage();
            $resp->data = null;
            $this->setResult($resp);
            return $this;
        }
    }

    public function getDriverStatistics($filters = [])
    {
        try {
            $result = new \stdClass();
            $whereClause = "WHERE 1=1";
            $params = [];

            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $whereClause .= " AND s.created_at >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to']) && !empty($filters['date_to'])) {
                $whereClause .= " AND s.created_at <= :date_to";
                $params['date_to'] = $filters['date_to'];
            }

            // Rendimiento por conductor
            $driverPerformanceQuery = "SELECT 
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                COUNT(s.id) as shipment_count,
                SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN s.status = 'en_transito' THEN 1 ELSE 0 END) as in_transit_count,
                SUM(CASE WHEN s.status = 'pendiente' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN s.status = 'cancelado' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(s.shipping_cost) as total_revenue,
                CASE 
                    WHEN COUNT(s.id) > 0 THEN (SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) / COUNT(s.id)) * 100
                    ELSE 0
                END as completion_rate
                FROM users u
                LEFT JOIN shipments s ON u.id = s.driver_id $whereClause
                WHERE u.role = 'transportista' AND u.active = 1
                GROUP BY u.id, driver_name
                ORDER BY shipment_count DESC";
            $this->getAllWithParams($driverPerformanceQuery, $params);
            $result->driverPerformance = $this->getResult()->data ?? [];

            // Envíos por conductor a lo largo del tiempo
            if (isset($filters['driver_id']) && !empty($filters['driver_id'])) {
                $driverTimelineQuery = "SELECT 
                    DATE_FORMAT(s.created_at, '%Y-%m') as month,
                    COUNT(s.id) as shipment_count,
                    SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as completed_count
                    FROM shipments s
                    WHERE s.driver_id = :driver_id
                    GROUP BY month
                    ORDER BY month";
                $this->getAllWithParams($driverTimelineQuery, ['driver_id' => $filters['driver_id']]);
                $result->driverTimeline = $this->getResult()->data ?? [];
            }

            $resp = new \stdClass();
            $resp->ok = true;
            $resp->msg = "Estadísticas de conductores obtenidas correctamente";
            $resp->data = $result;

            $this->setResult($resp);
            return $this;
        } catch (\Exception $e) {
            $resp = new \stdClass();
            $resp->ok = false;
            $resp->msg = $e->getMessage();
            $resp->data = null;
            $this->setResult($resp);
            return $this;
        }
    }

    public function getDashboardStatistics()
    {
        try {
            $result = new \stdClass();

            // Estadísticas para el dashboard (resumen general)

            // Contadores básicos
            $countsQuery = "SELECT
                (SELECT COUNT(*) FROM shipments) as total_shipments,
                (SELECT COUNT(*) FROM shipments WHERE status = 'pendiente') as pending_shipments,
                (SELECT COUNT(*) FROM shipments WHERE status = 'en_transito') as in_transit_shipments,
                (SELECT COUNT(*) FROM shipments WHERE status = 'entregado') as delivered_shipments,
                (SELECT COUNT(*) FROM invoices) as total_invoices,
                (SELECT COUNT(*) FROM invoices WHERE status = 'pendiente') as pending_invoices,
                (SELECT COUNT(*) FROM invoices WHERE status = 'pagada') as paid_invoices,
                (SELECT COUNT(*) FROM clients WHERE status = 'active') as active_clients,
                (SELECT COUNT(*) FROM users WHERE role = 'transportista' AND active = 1) as active_drivers";
            $this->getOne($countsQuery, []);
            $result->counts = $this->getResult()->data ?? null;

            // Resumen financiero
            $financialSummaryQuery = "SELECT
                (SELECT SUM(total) FROM invoices) as total_invoiced,
                (SELECT SUM(total) FROM invoices WHERE status = 'pagada') as total_collected,
                (SELECT SUM(total) FROM invoices WHERE status = 'pendiente') as total_pending,
                (SELECT SUM(shipping_cost) FROM shipments) as total_shipping_revenue";
            $this->getOne($financialSummaryQuery, []);
            $result->financialSummary = $this->getResult()->data ?? null;

            // Envíos recientes
            $recentShipmentsQuery = "SELECT 
                s.id, s.ref_code, s.customer, s.origin_address, s.destination_address, s.status, s.created_at,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name
                FROM shipments s
                LEFT JOIN users u ON s.driver_id = u.id
                ORDER BY s.created_at DESC
                LIMIT 5";
            $this->getAllWithParams($recentShipmentsQuery, []);
            $result->recentShipments = $this->getResult()->data ?? [];

            // Facturas recientes
            $recentInvoicesQuery = "SELECT 
                id, invoice_number, customer, total, status, issue_date, due_date
                FROM invoices
                ORDER BY issue_date DESC
                LIMIT 5";
            $this->getAllWithParams($recentInvoicesQuery, []);
            $result->recentInvoices = $this->getResult()->data ?? [];

            // Datos para gráficos de actividad reciente
            $currentYear = date('Y');
            $lastMonthsQuery = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as shipment_count
                FROM shipments
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY month
                ORDER BY month";
            $this->getAllWithParams($lastMonthsQuery, []);
            $result->recentActivityByMonth = $this->getResult()->data ?? [];

            // Ingresos mensuales
            $monthlyRevenueQuery = "SELECT 
                DATE_FORMAT(issue_date, '%Y-%m') as month,
                SUM(total) as total_amount
                FROM invoices
                WHERE issue_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY month
                ORDER BY month";
            $this->getAllWithParams($monthlyRevenueQuery, []);
            $result->monthlyRevenue = $this->getResult()->data ?? [];

            $resp = new \stdClass();
            $resp->ok = true;
            $resp->msg = "Estadísticas del dashboard obtenidas correctamente";
            $resp->data = $result;

            $this->setResult($resp);
            return $this;
        } catch (\Exception $e) {
            $resp = new \stdClass();
            $resp->ok = false;
            $resp->msg = $e->getMessage();
            $resp->data = null;
            $this->setResult($resp);
            return $this;
        }
    }
}
