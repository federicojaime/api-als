<?php

namespace objects;

class Statistics extends Base
{
    public function __construct($db)
    {
        parent::__construct($db);
    }

    public function getGeneralStatistics($filters = [])
    {
        try {
            $result = new \stdClass();
            $whereClause = "";
            $params = [];

            // Aplicar filtros de fecha si existen
            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $shipmentWhereClause = " WHERE created_at >= :date_from";
                $invoiceWhereClause = " WHERE issue_date >= :date_from";
                $params['date_from'] = $filters['date_from'];
            } else {
                $shipmentWhereClause = "";
                $invoiceWhereClause = "";
            }

            if (isset($filters['date_to']) && !empty($filters['date_to'])) {
                if (empty($shipmentWhereClause)) {
                    $shipmentWhereClause = " WHERE created_at <= :date_to";
                    $invoiceWhereClause = " WHERE issue_date <= :date_to";
                } else {
                    $shipmentWhereClause .= " AND created_at <= :date_to";
                    $invoiceWhereClause .= " AND issue_date <= :date_to";
                }
                $params['date_to'] = $filters['date_to'];
            }

            // Obtener cantidad de envíos por estado con filtros
            $shipmentsByStatusQuery = "SELECT status, COUNT(*) as count FROM shipments" . $shipmentWhereClause . " GROUP BY status";
            $this->getAllWithParams($shipmentsByStatusQuery, $params);
            $result->shipmentsByStatus = $this->getResult()->data ?? [];

            // Obtener total de envíos por estado
            $shipmentsTotalQuery = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN status = 'en_transito' THEN 1 ELSE 0 END) as en_transito,
                SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) as entregados,
                SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelados
                FROM shipments" . $shipmentWhereClause;
            $this->getOne($shipmentsTotalQuery, $params);
            $result->shipmentsTotals = $this->getResult()->data ?? null;

            // Obtener cantidad de facturas por estado
            $invoicesByStatusQuery = "SELECT status, COUNT(*) as count FROM invoices" . $invoiceWhereClause . " GROUP BY status";
            $this->getAllWithParams($invoicesByStatusQuery, $params);
            $result->invoicesByStatus = $this->getResult()->data ?? [];

            // Obtener cantidad de clientes activos/inactivos
            $clientsByStatusQuery = "SELECT status, COUNT(*) as count FROM clients GROUP BY status";
            $this->getAllWithParams($clientsByStatusQuery, []);
            $result->clientsByStatus = $this->getResult()->data ?? [];

            // Obtener total de usuarios por rol
            $usersByRoleQuery = "SELECT role, COUNT(*) as count FROM users WHERE active = true GROUP BY role";
            $this->getAllWithParams($usersByRoleQuery, []);
            $result->usersByRole = $this->getResult()->data ?? [];

            // Estadísticas financieras con filtros
            $financialStatsQuery = "SELECT 
                SUM(total) as total_invoiced, 
                SUM(CASE WHEN status = 'pagada' THEN total ELSE 0 END) as total_collected,
                SUM(CASE WHEN status = 'pendiente' THEN total ELSE 0 END) as total_pending
                FROM invoices" . $invoiceWhereClause;
            $this->getOne($financialStatsQuery, $params);
            $result->financialStats = $this->getResult()->data ?? null;

            // Top 5 clientes por número de envíos
            $topClientsQuery = "SELECT 
                c.id, 
                c.business_name,
                COUNT(s.id) as shipment_count,
                SUM(s.shipping_cost) as total_revenue
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                " . (empty($shipmentWhereClause) ? "WHERE 1=1" : $shipmentWhereClause) . "
                GROUP BY c.id, c.business_name
                ORDER BY shipment_count DESC
                LIMIT 5";
            $this->getAllWithParams($topClientsQuery, $params);
            $result->topClients = $this->getResult()->data ?? [];

            // Top 5 transportistas por número de envíos
            $topDriversQuery = "SELECT 
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                COUNT(s.id) as shipment_count,
                SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
                SUM(s.shipping_cost) as total_revenue,
                CASE 
                    WHEN COUNT(s.id) > 0 THEN ROUND((SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) / COUNT(s.id)) * 100, 2)
                    ELSE 0
                END as completion_rate
                FROM users u
                JOIN shipments s ON u.id = s.driver_id
                " . (empty($shipmentWhereClause) ? "WHERE u.role = 'transportista'" : $shipmentWhereClause . " AND u.role = 'transportista'") . "
                GROUP BY u.id, driver_name
                ORDER BY shipment_count DESC
                LIMIT 5";
            $this->getAllWithParams($topDriversQuery, $params);
            $result->topDrivers = $this->getResult()->data ?? [];

            // Tendencia mensual de envíos en los últimos 6 meses
            $monthlyTrendQuery = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as shipment_count,
                SUM(shipping_cost) as total_revenue
                FROM shipments
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY month
                ORDER BY month";
            $this->getAllWithParams($monthlyTrendQuery, []);
            $result->monthlyTrend = $this->getResult()->data ?? [];

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

            if (isset($filters['status']) && !empty($filters['status']) && $filters['status'] !== 'todos') {
                $whereClause .= " AND status = :status";
                $params['status'] = $filters['status'];
            }

            // Resumen por estado
            $statusSummaryQuery = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN status = 'en_transito' THEN 1 ELSE 0 END) as en_transito,
                SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) as entregados,
                SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelados,
                SUM(shipping_cost) as total_revenue
                FROM shipments 
                $whereClause";
            $this->getOne($statusSummaryQuery, $params);
            $result->statusSummary = $this->getResult()->data ?? null;

            // Envíos por mes
            $shipmentsByMonthQuery = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month, 
                COUNT(*) as count,
                SUM(shipping_cost) as total_revenue
                FROM shipments 
                $whereClause 
                GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
                ORDER BY month DESC";
            $this->getAllWithParams($shipmentsByMonthQuery, $params);
            $result->shipmentsByMonth = $this->getResult()->data ?? [];

            // Envíos por estado
            $shipmentsByStatusQuery = "SELECT 
                status, 
                COUNT(*) as count,
                SUM(shipping_cost) as total_revenue
                FROM shipments 
                $whereClause 
                GROUP BY status";
            $this->getAllWithParams($shipmentsByStatusQuery, $params);
            $result->shipmentsByStatus = $this->getResult()->data ?? [];

            // Top 10 conductores por número de envíos
            $topDriversQuery = "SELECT 
                s.driver_id, 
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                COUNT(*) as count,
                SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
                SUM(s.shipping_cost) as total_revenue,
                CASE 
                    WHEN COUNT(*) > 0 THEN ROUND((SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2)
                    ELSE 0
                END as completion_rate
                FROM shipments s
                LEFT JOIN users u ON s.driver_id = u.id
                $whereClause 
                GROUP BY s.driver_id, driver_name
                ORDER BY count DESC
                LIMIT 10";
            $this->getAllWithParams($topDriversQuery, $params);
            $result->topDrivers = $this->getResult()->data ?? [];

            // Top 10 clientes por número de envíos
            $topClientsQuery = "SELECT 
                s.client_id, 
                c.business_name as client_name,
                COUNT(*) as count,
                SUM(s.shipping_cost) as total_revenue
                FROM shipments s
                LEFT JOIN clients c ON s.client_id = c.id
                $whereClause 
                GROUP BY s.client_id, client_name
                ORDER BY count DESC
                LIMIT 10";
            $this->getAllWithParams($topClientsQuery, $params);
            $result->topClients = $this->getResult()->data ?? [];

            // Distribución por día de la semana
            $dayOfWeekQuery = "SELECT 
                DAYOFWEEK(created_at) as day_number,
                DAYNAME(created_at) as day_name,
                COUNT(*) as count,
                SUM(shipping_cost) as total_revenue
                FROM shipments
                $whereClause
                GROUP BY day_number, day_name
                ORDER BY day_number";
            $this->getAllWithParams($dayOfWeekQuery, $params);
            $result->shipmentsByDayOfWeek = $this->getResult()->data ?? [];

            // Envíos por origen/destino
            $locationQuery = "SELECT 
                origin_address,
                destination_address,
                COUNT(*) as count
                FROM shipments
                $whereClause
                GROUP BY origin_address, destination_address
                ORDER BY count DESC
                LIMIT 10";
            $this->getAllWithParams($locationQuery, $params);
            $result->topRoutes = $this->getResult()->data ?? [];

            // Ingresos totales y promedio por envío
            $revenueQuery = "SELECT 
                SUM(shipping_cost) as total_revenue,
                AVG(shipping_cost) as avg_revenue,
                MAX(shipping_cost) as max_revenue,
                MIN(shipping_cost) as min_revenue
                FROM shipments 
                $whereClause";
            $this->getOne($revenueQuery, $params);
            $result->revenueStats = $this->getResult()->data ?? null;

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

            // Resumen por estado
            $statusSummaryQuery = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN status = 'pagada' THEN 1 ELSE 0 END) as pagadas,
                SUM(CASE WHEN status = 'vencida' THEN 1 ELSE 0 END) as vencidas,
                SUM(CASE WHEN status = 'cancelada' THEN 1 ELSE 0 END) as canceladas,
                SUM(total) as total_amount,
                SUM(CASE WHEN status = 'pagada' THEN total ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = 'pendiente' THEN total ELSE 0 END) as total_pending
                FROM invoices 
                $whereClause";
            $this->getOne($statusSummaryQuery, $params);
            $result->statusSummary = $this->getResult()->data ?? null;

            // Facturas por mes
            $invoicesByMonthQuery = "SELECT 
                DATE_FORMAT(issue_date, '%Y-%m') as month, 
                COUNT(*) as count,
                SUM(total) as total_amount,
                SUM(CASE WHEN status = 'pagada' THEN total ELSE 0 END) as paid_amount
                FROM invoices 
                $whereClause 
                GROUP BY DATE_FORMAT(issue_date, '%Y-%m') 
                ORDER BY month DESC";
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

            // Top 10 clientes por facturación
            $topClientsQuery = "SELECT 
                customer, 
                COUNT(*) as count,
                SUM(total) as total_amount,
                SUM(CASE WHEN status = 'pagada' THEN total ELSE 0 END) as paid_amount,
                SUM(CASE WHEN status = 'pendiente' THEN total ELSE 0 END) as pending_amount
                FROM invoices 
                $whereClause 
                GROUP BY customer
                ORDER BY total_amount DESC
                LIMIT 10";
            $this->getAllWithParams($topClientsQuery, $params);
            $result->topClients = $this->getResult()->data ?? [];

            // Facturas por día de la semana
            $dayOfWeekQuery = "SELECT 
                DAYOFWEEK(issue_date) as day_number,
                DAYNAME(issue_date) as day_name,
                COUNT(*) as count,
                SUM(total) as total_amount
                FROM invoices
                $whereClause
                GROUP BY day_number, day_name
                ORDER BY day_number";
            $this->getAllWithParams($dayOfWeekQuery, $params);
            $result->invoicesByDayOfWeek = $this->getResult()->data ?? [];

            // Montos promedio, mínimo y máximo
            $amountStatsQuery = "SELECT 
                AVG(total) as average_total,
                MIN(total) as min_total,
                MAX(total) as max_total,
                AVG(DATEDIFF(due_date, issue_date)) as avg_payment_term
                FROM invoices 
                $whereClause";
            $this->getOne($amountStatsQuery, $params);
            $result->amountStats = $this->getResult()->data ?? null;

            // Análisis de pagos a tiempo vs retrasados
            $paymentTimingQuery = "SELECT 
                COUNT(*) as total_paid,
                SUM(CASE WHEN DATEDIFF(updated_at, due_date) <= 0 THEN 1 ELSE 0 END) as paid_on_time,
                SUM(CASE WHEN DATEDIFF(updated_at, due_date) > 0 THEN 1 ELSE 0 END) as paid_late,
                AVG(CASE WHEN DATEDIFF(updated_at, due_date) > 0 THEN DATEDIFF(updated_at, due_date) ELSE 0 END) as avg_days_late
                FROM invoices
                WHERE status = 'pagada'
                " . (strpos($whereClause, 'WHERE 1=1') !== false ? str_replace('WHERE 1=1', '', $whereClause) : $whereClause);
            $this->getOne($paymentTimingQuery, $params);
            $result->paymentTiming = $this->getResult()->data ?? null;

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

            // Clientes más activos (por número de envíos)
            $activeClientsQuery = "SELECT 
                c.id,
                c.business_name,
                COUNT(s.id) as shipment_count,
                SUM(s.shipping_cost) as total_revenue,
                MAX(s.created_at) as last_shipment_date
                FROM clients c
                LEFT JOIN shipments s ON c.id = s.client_id
                " . str_replace('WHERE 1=1', 'WHERE c.status = \'active\'', $whereClause) . "
                GROUP BY c.id, c.business_name
                ORDER BY shipment_count DESC
                LIMIT 10";
            $this->getAllWithParams($activeClientsQuery, $params);
            $result->topActiveClients = $this->getResult()->data ?? [];

            // Clientes por volumen de facturación
            $clientsByBillingQuery = "SELECT 
                c.id,
                c.business_name,
                SUM(i.total) as total_billed,
                COUNT(i.id) as invoice_count,
                SUM(CASE WHEN i.status = 'pagada' THEN i.total ELSE 0 END) as total_paid,
                SUM(CASE WHEN i.status = 'pendiente' THEN i.total ELSE 0 END) as total_pending
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                JOIN invoices i ON s.id = i.shipment_id
                " . $whereClause . "
                GROUP BY c.id, c.business_name
                ORDER BY total_billed DESC
                LIMIT 10";
            $this->getAllWithParams($clientsByBillingQuery, $params);
            $result->topClientsByBilling = $this->getResult()->data ?? [];

            // Clientes por tasa de cumplimiento de pago
            $clientsByPaymentQuery = "SELECT 
                c.id,
                c.business_name,
                COUNT(i.id) as invoice_count,
                SUM(CASE WHEN i.status = 'pagada' THEN 1 ELSE 0 END) as paid_count,
                CASE 
                    WHEN COUNT(i.id) > 0 THEN ROUND((SUM(CASE WHEN i.status = 'pagada' THEN 1 ELSE 0 END) / COUNT(i.id)) * 100, 2)
                    ELSE 0
                END as payment_rate,
                SUM(i.total) as total_billed
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                JOIN invoices i ON s.id = i.shipment_id
                " . $whereClause . "
                GROUP BY c.id, c.business_name
                HAVING invoice_count > 3
                ORDER BY payment_rate DESC
                LIMIT 10";
            $this->getAllWithParams($clientsByPaymentQuery, $params);
            $result->topClientsByPaymentRate = $this->getResult()->data ?? [];

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
                c.id,
                c.business_name,
                SUM(CASE WHEN i.status = 'pendiente' THEN i.total ELSE 0 END) as pending_amount,
                COUNT(CASE WHEN i.status = 'pendiente' THEN i.id END) as pending_count,
                MAX(i.due_date) as next_due_date
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                JOIN invoices i ON s.id = i.shipment_id
                " . $whereClause . "
                GROUP BY c.id, c.business_name
                HAVING pending_amount > 0
                ORDER BY pending_amount DESC
                LIMIT 10";
            $this->getAllWithParams($clientsWithPendingQuery, $params);
            $result->clientsWithPending = $this->getResult()->data ?? [];

            // Clientes por tiempo promedio de pago
            $clientsByPaymentTimeQuery = "SELECT 
                c.id,
                c.business_name,
                COUNT(CASE WHEN i.status = 'pagada' THEN i.id END) as paid_invoices,
                AVG(DATEDIFF(i.updated_at, i.issue_date)) as avg_payment_days
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                JOIN invoices i ON s.id = i.shipment_id
                " . str_replace('WHERE 1=1', 'WHERE i.status = \'pagada\'', $whereClause) . "
                GROUP BY c.id, c.business_name
                HAVING paid_invoices >= 3
                ORDER BY avg_payment_days ASC
                LIMIT 10";
            $this->getAllWithParams($clientsByPaymentTimeQuery, $params);
            $result->topClientsByPaymentTime = $this->getResult()->data ?? [];

            // Clientes nuevos en el período
            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $newClientsQuery = "SELECT 
                    id,
                    business_name,
                    created_at
                    FROM clients
                    WHERE created_at >= :date_from
                    " . (isset($filters['date_to']) && !empty($filters['date_to']) ? "AND created_at <= :date_to" : "") . "
                    ORDER BY created_at DESC";
                $this->getAllWithParams($newClientsQuery, $params);
                $result->newClients = $this->getResult()->data ?? [];
            }

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

            if (isset($filters['driver_id']) && !empty($filters['driver_id'])) {
                $whereClause .= " AND s.driver_id = :driver_id";
                $params['driver_id'] = $filters['driver_id'];
            }

            // Resumen general por conductor
            $driverSummaryQuery = "SELECT 
                COUNT(DISTINCT u.id) as total_active_drivers,
                AVG(driver_stats.shipment_count) as avg_shipments_per_driver,
                MAX(driver_stats.shipment_count) as max_shipments_by_driver,
                AVG(driver_stats.completion_rate) as avg_completion_rate
                FROM users u
                LEFT JOIN (
                    SELECT 
                        driver_id,
                        COUNT(id) as shipment_count,
                        SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
                        CASE 
                            WHEN COUNT(id) > 0 THEN (SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) / COUNT(id)) * 100
                            ELSE 0
                        END as completion_rate
                    FROM shipments s
                    " . str_replace('WHERE 1=1', 'WHERE 1=1', $whereClause) . "
                    GROUP BY driver_id
                ) as driver_stats ON u.id = driver_stats.driver_id
                WHERE u.role = 'transportista' AND u.active = 1";
            $this->getOne($driverSummaryQuery, $params);
            $result->driverSummary = $this->getResult()->data ?? null;

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
                    WHEN COUNT(s.id) > 0 THEN ROUND((SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) / COUNT(s.id)) * 100, 2)
                    ELSE 0
                END as completion_rate
                FROM users u
                LEFT JOIN shipments s ON u.id = s.driver_id " . str_replace('WHERE 1=1', '', $whereClause) . "
                WHERE u.role = 'transportista' AND u.active = 1
                GROUP BY u.id, driver_name
                ORDER BY shipment_count DESC";
            $this->getAllWithParams($driverPerformanceQuery, $params);
            $result->driverPerformance = $this->getResult()->data ?? [];

            // Top 10 conductores
            $topDriversQuery = "SELECT 
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                COUNT(s.id) as shipment_count,
                SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
                SUM(s.shipping_cost) as total_revenue,
                ROUND((SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) / COUNT(s.id)) * 100, 2) as completion_rate
                FROM users u
                JOIN shipments s ON u.id = s.driver_id
                " . str_replace('WHERE 1=1', 'WHERE u.role = \'transportista\' AND u.active = 1', $whereClause) . "
                GROUP BY u.id, driver_name
                ORDER BY shipment_count DESC
                LIMIT 10";
            $this->getAllWithParams($topDriversQuery, $params);
            $result->topDrivers = $this->getResult()->data ?? [];

            // Distribución por estado para cada conductor
            if (isset($filters['driver_id']) && !empty($filters['driver_id'])) {
                $driverStatusQuery = "SELECT 
                    status,
                    COUNT(id) as count,
                    SUM(shipping_cost) as total_revenue
                    FROM shipments
                    WHERE driver_id = :driver_id 
                    " . (isset($filters['date_from']) ? "AND created_at >= :date_from" : "") . "
                    " . (isset($filters['date_to']) ? "AND created_at <= :date_to" : "") . "
                    GROUP BY status";
                $this->getAllWithParams($driverStatusQuery, $params);
                $result->driverStatusDistribution = $this->getResult()->data ?? [];

                // Historial mensual del conductor
                $driverMonthlyQuery = "SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(id) as shipment_count,
                    SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
                    SUM(shipping_cost) as total_revenue,
                    ROUND((SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) / COUNT(id)) * 100, 2) as completion_rate
                    FROM shipments
                    WHERE driver_id = :driver_id
                    " . (isset($filters['date_from']) ? "AND created_at >= :date_from" : "") . "
                    " . (isset($filters['date_to']) ? "AND created_at <= :date_to" : "") . "
                    GROUP BY month
                    ORDER BY month";
                $this->getAllWithParams($driverMonthlyQuery, $params);
                $result->driverMonthlyPerformance = $this->getResult()->data ?? [];

                // Clientes más frecuentes para el conductor
                $driverTopClientsQuery = "SELECT 
                    c.id,
                    c.business_name as client_name,
                    COUNT(s.id) as shipment_count,
                    SUM(s.shipping_cost) as total_revenue
                    FROM shipments s
                    JOIN clients c ON s.client_id = c.id
                    WHERE s.driver_id = :driver_id
                    " . (isset($filters['date_from']) ? "AND s.created_at >= :date_from" : "") . "
                    " . (isset($filters['date_to']) ? "AND s.created_at <= :date_to" : "") . "
                    GROUP BY c.id, client_name
                    ORDER BY shipment_count DESC
                    LIMIT 5";
                $this->getAllWithParams($driverTopClientsQuery, $params);
                $result->driverTopClients = $this->getResult()->data ?? [];
            }

            // Conductores por volumen de ingresos
            $driversByRevenueQuery = "SELECT 
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                SUM(s.shipping_cost) as total_revenue,
                COUNT(s.id) as shipment_count,
                ROUND(SUM(s.shipping_cost) / COUNT(s.id), 2) as avg_revenue_per_shipment
                FROM users u
                JOIN shipments s ON u.id = s.driver_id
                " . str_replace('WHERE 1=1', 'WHERE u.role = \'transportista\' AND u.active = 1', $whereClause) . "
                GROUP BY u.id, driver_name
                ORDER BY total_revenue DESC
                LIMIT 10";
            $this->getAllWithParams($driversByRevenueQuery, $params);
            $result->topDriversByRevenue = $this->getResult()->data ?? [];

            // Análisis de eficiencia (envíos por día)
            $driverEfficiencyQuery = "SELECT 
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                COUNT(DISTINCT DATE(s.created_at)) as working_days,
                COUNT(s.id) as total_shipments,
                ROUND(COUNT(s.id) / COUNT(DISTINCT DATE(s.created_at)), 2) as shipments_per_day
                FROM users u
                JOIN shipments s ON u.id = s.driver_id
                " . str_replace('WHERE 1=1', 'WHERE u.role = \'transportista\' AND u.active = 1', $whereClause) . "
                GROUP BY u.id, driver_name
                HAVING working_days > 0
                ORDER BY shipments_per_day DESC
                LIMIT 10";
            $this->getAllWithParams($driverEfficiencyQuery, $params);
            $result->driverEfficiency = $this->getResult()->data ?? [];

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

    public function getDashboardStatistics($filters = [])
    {
        try {
            $result = new \stdClass();
            $whereClauseShipments = "";
            $whereClauseInvoices = "";
            $params = [];

            // Aplicar filtros de fecha si existen
            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $whereClauseShipments = " WHERE created_at >= :date_from";
                $whereClauseInvoices = " WHERE issue_date >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to']) && !empty($filters['date_to'])) {
                if (empty($whereClauseShipments)) {
                    $whereClauseShipments = " WHERE created_at <= :date_to";
                    $whereClauseInvoices = " WHERE issue_date <= :date_to";
                } else {
                    $whereClauseShipments .= " AND created_at <= :date_to";
                    $whereClauseInvoices .= " AND issue_date <= :date_to";
                }
                $params['date_to'] = $filters['date_to'];
            }

            // Estadísticas para el dashboard (resumen general)

            // Contadores básicos
            $countsQuery = "SELECT
                (SELECT COUNT(*) FROM shipments" . $whereClauseShipments . ") as total_shipments,
                (SELECT COUNT(*) FROM shipments WHERE status = 'pendiente'" .
                (empty($whereClauseShipments) ? "" : str_replace('WHERE', 'AND', $whereClauseShipments)) . ") as pending_shipments,
                (SELECT COUNT(*) FROM shipments WHERE status = 'en_transito'" .
                (empty($whereClauseShipments) ? "" : str_replace('WHERE', 'AND', $whereClauseShipments)) . ") as in_transit_shipments,
                (SELECT COUNT(*) FROM shipments WHERE status = 'entregado'" .
                (empty($whereClauseShipments) ? "" : str_replace('WHERE', 'AND', $whereClauseShipments)) . ") as delivered_shipments,
                (SELECT COUNT(*) FROM shipments WHERE status = 'cancelado'" .
                (empty($whereClauseShipments) ? "" : str_replace('WHERE', 'AND', $whereClauseShipments)) . ") as cancelled_shipments,
                (SELECT COUNT(*) FROM invoices" . $whereClauseInvoices . ") as total_invoices,
                (SELECT COUNT(*) FROM invoices WHERE status = 'pendiente'" .
                (empty($whereClauseInvoices) ? "" : str_replace('WHERE', 'AND', $whereClauseInvoices)) . ") as pending_invoices,
                (SELECT COUNT(*) FROM invoices WHERE status = 'pagada'" .
                (empty($whereClauseInvoices) ? "" : str_replace('WHERE', 'AND', $whereClauseInvoices)) . ") as paid_invoices,
                (SELECT COUNT(*) FROM clients WHERE status = 'active') as active_clients,
                (SELECT COUNT(*) FROM users WHERE role = 'transportista' AND active = 1) as active_drivers";
            $this->getOne($countsQuery, $params);
            $result->counts = $this->getResult()->data ?? null;

            // Resumen financiero
            $financialSummaryQuery = "SELECT
                (SELECT SUM(total) FROM invoices" . $whereClauseInvoices . ") as total_invoiced,
                (SELECT SUM(total) FROM invoices WHERE status = 'pagada'" .
                (empty($whereClauseInvoices) ? "" : str_replace('WHERE', 'AND', $whereClauseInvoices)) . ") as total_collected,
                (SELECT SUM(total) FROM invoices WHERE status = 'pendiente'" .
                (empty($whereClauseInvoices) ? "" : str_replace('WHERE', 'AND', $whereClauseInvoices)) . ") as total_pending,
                (SELECT SUM(shipping_cost) FROM shipments" . $whereClauseShipments . ") as total_shipping_revenue";
            $this->getOne($financialSummaryQuery, $params);
            $result->financialSummary = $this->getResult()->data ?? null;

            // Top 5 envíos recientes
            $recentShipmentsQuery = "SELECT 
                s.id, s.ref_code, s.customer, s.origin_address, s.destination_address, s.status, s.created_at,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name
                FROM shipments s
                LEFT JOIN users u ON s.driver_id = u.id
                " . $whereClauseShipments . "
                ORDER BY s.created_at DESC
                LIMIT 5";
            $this->getAllWithParams($recentShipmentsQuery, $params);
            $result->recentShipments = $this->getResult()->data ?? [];

            // Top 5 facturas recientes
            $recentInvoicesQuery = "SELECT 
                id, invoice_number, customer, total, status, issue_date, due_date
                FROM invoices
                " . $whereClauseInvoices . "
                ORDER BY issue_date DESC
                LIMIT 5";
            $this->getAllWithParams($recentInvoicesQuery, $params);
            $result->recentInvoices = $this->getResult()->data ?? [];

            // Top 5 clientes por número de envíos
            $topClientsQuery = "SELECT 
                c.id, 
                c.business_name,
                COUNT(s.id) as shipment_count,
                SUM(s.shipping_cost) as total_revenue
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                " . $whereClauseShipments . "
                GROUP BY c.id, c.business_name
                ORDER BY shipment_count DESC
                LIMIT 5";
            $this->getAllWithParams($topClientsQuery, $params);
            $result->topClients = $this->getResult()->data ?? [];

            // Top 5 conductores por número de envíos
            $topDriversQuery = "SELECT 
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                COUNT(s.id) as shipment_count,
                SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
                SUM(s.shipping_cost) as total_revenue,
                ROUND((SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) / COUNT(s.id)) * 100, 2) as completion_rate
                FROM users u
                JOIN shipments s ON u.id = s.driver_id
                " . (empty($whereClauseShipments) ? " WHERE u.role = 'transportista'" : $whereClauseShipments . " AND u.role = 'transportista'") . "
                GROUP BY u.id, driver_name
                ORDER BY shipment_count DESC
                LIMIT 5";
            $this->getAllWithParams($topDriversQuery, $params);
            $result->topDrivers = $this->getResult()->data ?? [];

            // Datos para gráficos de actividad reciente (últimos 6 meses)
            $lastMonthsQuery = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as shipment_count,
                SUM(shipping_cost) as total_revenue
                FROM shipments
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                " . (empty($whereClauseShipments) ? "" : str_replace('WHERE', 'AND', $whereClauseShipments)) . "
                GROUP BY month
                ORDER BY month";
            $this->getAllWithParams($lastMonthsQuery, $params);
            $result->recentActivityByMonth = $this->getResult()->data ?? [];

            // Ingresos mensuales (últimos 6 meses)
            $monthlyRevenueQuery = "SELECT 
                DATE_FORMAT(issue_date, '%Y-%m') as month,
                SUM(total) as total_amount,
                SUM(CASE WHEN status = 'pagada' THEN total ELSE 0 END) as paid_amount
                FROM invoices
                WHERE issue_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                " . (empty($whereClauseInvoices) ? "" : str_replace('WHERE', 'AND', $whereClauseInvoices)) . "
                GROUP BY month
                ORDER BY month";
            $this->getAllWithParams($monthlyRevenueQuery, $params);
            $result->monthlyRevenue = $this->getResult()->data ?? [];

            // Distribución por día de la semana (envíos)
            $dayOfWeekQuery = "SELECT 
                DAYOFWEEK(created_at) as day_number,
                DAYNAME(created_at) as day_name,
                COUNT(*) as count
                FROM shipments
                " . $whereClauseShipments . "
                GROUP BY day_number, day_name
                ORDER BY day_number";
            $this->getAllWithParams($dayOfWeekQuery, $params);
            $result->shipmentsByDayOfWeek = $this->getResult()->data ?? [];

            // Resumen de KPIs clave
            $kpiQuery = "SELECT
                (SELECT COUNT(*) FROM shipments WHERE status = 'entregado'" .
                (empty($whereClauseShipments) ? "" : str_replace('WHERE', 'AND', $whereClauseShipments)) . ") / 
                (SELECT COUNT(*) FROM shipments" . $whereClauseShipments . ") * 100 as delivery_success_rate,
                (SELECT COUNT(*) FROM invoices WHERE status = 'pagada'" .
                (empty($whereClauseInvoices) ? "" : str_replace('WHERE', 'AND', $whereClauseInvoices)) . ") / 
                (SELECT COUNT(*) FROM invoices" . $whereClauseInvoices . ") * 100 as payment_success_rate,
                (SELECT AVG(shipping_cost) FROM shipments" . $whereClauseShipments . ") as avg_shipment_value,
                (SELECT AVG(total) FROM invoices" . $whereClauseInvoices . ") as avg_invoice_value";
            $this->getOne($kpiQuery, $params);
            $result->kpis = $this->getResult()->data ?? null;

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

    public function getTopPerformers($filters = [])
    {
        try {
            $result = new \stdClass();
            $whereClauseShipments = "WHERE 1=1";
            $whereClauseInvoices = "WHERE 1=1";
            $params = [];

            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $whereClauseShipments .= " AND created_at >= :date_from";
                $whereClauseInvoices .= " AND issue_date >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to']) && !empty($filters['date_to'])) {
                $whereClauseShipments .= " AND created_at <= :date_to";
                $whereClauseInvoices .= " AND issue_date <= :date_to";
                $params['date_to'] = $filters['date_to'];
            }

            // Top 10 clientes por número de envíos
            $topClientsShipmentsQuery = "SELECT 
                c.id, 
                c.business_name,
                COUNT(s.id) as shipment_count,
                SUM(s.shipping_cost) as total_revenue
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                $whereClauseShipments
                GROUP BY c.id, c.business_name
                ORDER BY shipment_count DESC
                LIMIT 10";
            $this->getAllWithParams($topClientsShipmentsQuery, $params);
            $result->topClientsByShipments = $this->getResult()->data ?? [];

            // Top 10 clientes por facturación
            $topClientsBillingQuery = "SELECT 
                c.id,
                c.business_name,
                SUM(i.total) as total_billed,
                COUNT(i.id) as invoice_count,
                SUM(CASE WHEN i.status = 'pagada' THEN i.total ELSE 0 END) as total_paid,
                SUM(CASE WHEN i.status = 'pendiente' THEN i.total ELSE 0 END) as total_pending
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                JOIN invoices i ON s.id = i.shipment_id
                $whereClauseInvoices
                GROUP BY c.id, c.business_name
                ORDER BY total_billed DESC
                LIMIT 10";
            $this->getAllWithParams($topClientsBillingQuery, $params);
            $result->topClientsByBilling = $this->getResult()->data ?? [];

            // Top 10 conductores por número de envíos
            $topDriversShipmentsQuery = "SELECT 
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                COUNT(s.id) as shipment_count,
                SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
                SUM(s.shipping_cost) as total_revenue,
                ROUND((SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) / COUNT(s.id)) * 100, 2) as completion_rate
                FROM users u
                JOIN shipments s ON u.id = s.driver_id
                $whereClauseShipments AND u.role = 'transportista'
                GROUP BY u.id, driver_name
                ORDER BY shipment_count DESC
                LIMIT 10";
            $this->getAllWithParams($topDriversShipmentsQuery, $params);
            $result->topDriversByShipments = $this->getResult()->data ?? [];

            // Top 10 conductores por ingresos generados
            $topDriversRevenueQuery = "SELECT 
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                SUM(s.shipping_cost) as total_revenue,
                COUNT(s.id) as shipment_count,
                ROUND(SUM(s.shipping_cost) / COUNT(s.id), 2) as avg_revenue_per_shipment
                FROM users u
                JOIN shipments s ON u.id = s.driver_id
                $whereClauseShipments AND u.role = 'transportista'
                GROUP BY u.id, driver_name
                ORDER BY total_revenue DESC
                LIMIT 10";
            $this->getAllWithParams($topDriversRevenueQuery, $params);
            $result->topDriversByRevenue = $this->getResult()->data ?? [];

            // Top 10 rutas más frecuentes
            $topRoutesQuery = "SELECT 
                origin_address,
                destination_address,
                COUNT(*) as count,
                SUM(shipping_cost) as total_revenue,
                AVG(shipping_cost) as avg_revenue
                FROM shipments
                $whereClauseShipments
                GROUP BY origin_address, destination_address
                ORDER BY count DESC
                LIMIT 10";
            $this->getAllWithParams($topRoutesQuery, $params);
            $result->topRoutes = $this->getResult()->data ?? [];

            // Top 10 mayores envíos por valor
            $topShipmentsByValueQuery = "SELECT 
                id,
                ref_code,
                customer,
                origin_address,
                destination_address,
                shipping_cost,
                created_at
                FROM shipments
                $whereClauseShipments
                ORDER BY shipping_cost DESC
                LIMIT 10";
            $this->getAllWithParams($topShipmentsByValueQuery, $params);
            $result->topShipmentsByValue = $this->getResult()->data ?? [];

            $resp = new \stdClass();
            $resp->ok = true;
            $resp->msg = "Top performers obtenidos correctamente";
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
