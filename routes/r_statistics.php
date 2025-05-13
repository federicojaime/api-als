<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use objects\Statistics;

// GET - Obtener estadísticas generales para el dashboard con filtros
$app->get("/statistics/dashboard", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getDashboardStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener todas las estadísticas generales con filtros opcionales
$app->get("/statistics/general", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getGeneralStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas de envíos con filtros
$app->get("/statistics/shipments", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getShipmentStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas de facturas con filtros
$app->get("/statistics/invoices", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getInvoiceStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas de clientes con filtros
$app->get("/statistics/clients", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getClientStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas de conductores con filtros
$app->get("/statistics/drivers", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getDriverStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas para un driver específico
$app->get("/statistics/driver/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $params['driver_id'] = $args['id'];

    $stats = new Statistics($this->get("db"));
    $resp = $stats->getDriverStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas para un cliente específico
$app->get("/statistics/client/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $params['client_id'] = $args['id'];

    $stats = new Statistics($this->get("db"));

    // Obtener estadísticas de envíos para este cliente
    $shipmentStats = $stats->getShipmentStatistics($params)->getResult();

    // Consulta personalizada para información de facturación del cliente
    $clientId = $args['id'];
    $db = $this->get("db");

    // Fechas de filtro si existen
    $dateFilter = "";
    $dateParams = [];
    if (isset($params['date_from']) && !empty($params['date_from'])) {
        $dateFilter .= " AND s.created_at >= :date_from";
        $dateParams['date_from'] = $params['date_from'];
    }
    if (isset($params['date_to']) && !empty($params['date_to'])) {
        $dateFilter .= " AND s.created_at <= :date_to";
        $dateParams['date_to'] = $params['date_to'];
    }

    $query = "SELECT 
        c.id,
        c.business_name,
        c.tax_id,
        c.email,
        c.phone,
        c.status,
        (SELECT COUNT(*) FROM shipments WHERE client_id = c.id $dateFilter) as total_shipments,
        (SELECT COUNT(*) FROM shipments WHERE client_id = c.id AND status = 'entregado' $dateFilter) as completed_shipments,
        (SELECT COUNT(*) FROM shipments WHERE client_id = c.id AND status = 'pendiente' $dateFilter) as pending_shipments,
        (SELECT COUNT(*) FROM shipments WHERE client_id = c.id AND status = 'en_transito' $dateFilter) as in_transit_shipments,
        (SELECT SUM(shipping_cost) FROM shipments WHERE client_id = c.id $dateFilter) as total_shipping_revenue,
        (SELECT COUNT(i.id) FROM invoices i JOIN shipments s ON i.shipment_id = s.id WHERE s.client_id = c.id $dateFilter) as total_invoices,
        (SELECT SUM(i.total) FROM invoices i JOIN shipments s ON i.shipment_id = s.id WHERE s.client_id = c.id $dateFilter) as total_invoiced,
       (SELECT SUM(i.total) FROM invoices i JOIN shipments s ON i.shipment_id = s.id WHERE s.client_id = c.id AND i.status = 'pagada' $dateFilter) as total_paid,
        (SELECT SUM(i.total) FROM invoices i JOIN shipments s ON i.shipment_id = s.id WHERE s.client_id = c.id AND i.status = 'pendiente' $dateFilter) as total_pending
        FROM clients c
        WHERE c.id = :client_id";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':client_id', $clientId, PDO::PARAM_INT);

    foreach ($dateParams as $param => $value) {
        $stmt->bindParam(":$param", $value);
    }

    $stmt->execute();
    $clientInfo = $stmt->fetch(PDO::FETCH_OBJ);

    // Obtener los últimos 5 envíos del cliente
    $recentShipmentsQuery = "SELECT 
        s.id, s.ref_code, s.customer, s.origin_address, s.destination_address, s.status, s.created_at, s.shipping_cost,
        CONCAT(u.firstname, ' ', u.lastname) as driver_name
        FROM shipments s
        LEFT JOIN users u ON s.driver_id = u.id
        WHERE s.client_id = :client_id 
        $dateFilter
        ORDER BY s.created_at DESC
        LIMIT 5";

    $stmt = $db->prepare($recentShipmentsQuery);
    $stmt->bindParam(':client_id', $clientId, PDO::PARAM_INT);

    foreach ($dateParams as $param => $value) {
        $stmt->bindParam(":$param", $value);
    }

    $stmt->execute();
    $recentShipments = $stmt->fetchAll(PDO::FETCH_OBJ);

    // Historial mensual de envíos del cliente
    $monthlyHistoryQuery = "SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as shipment_count,
        SUM(shipping_cost) as total_revenue
        FROM shipments
        WHERE client_id = :client_id
        $dateFilter
        GROUP BY month
        ORDER BY month DESC";

    $stmt = $db->prepare($monthlyHistoryQuery);
    $stmt->bindParam(':client_id', $clientId, PDO::PARAM_INT);

    foreach ($dateParams as $param => $value) {
        $stmt->bindParam(":$param", $value);
    }

    $stmt->execute();
    $monthlyHistory = $stmt->fetchAll(PDO::FETCH_OBJ);

    $result = new \stdClass();
    $result->ok = true;
    $result->msg = "Estadísticas del cliente obtenidas correctamente";
    $result->data = [
        'client_info' => $clientInfo,
        'shipments' => $shipmentStats->data ?? null,
        'recent_shipments' => $recentShipments,
        'monthly_history' => $monthlyHistory
    ];

    $response->getBody()->write(json_encode($result));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus(200);
});

// GET - Obtener los mejores performers (top clientes y conductores)
$app->get("/statistics/top", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getTopPerformers($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// Estadísticas para periodos específicos (semanal, mensual, anual)
$app->get("/statistics/period/{period}", function (Request $request, Response $response, array $args) {
    $period = $args['period'];
    $validPeriods = ['week', 'month', 'year', 'quarter'];

    if (!in_array($period, $validPeriods)) {
        $result = new \stdClass();
        $result->ok = false;
        $result->msg = "Periodo no válido. Use: " . implode(', ', $validPeriods);
        $result->data = null;

        $response->getBody()->write(json_encode($result));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(400);
    }

    $db = $this->get("db");
    $resultData = new \stdClass();

    try {
        // Determinar fecha inicial según el periodo
        $dateFrom = '';
        switch ($period) {
            case 'week':
                $dateFrom = date('Y-m-d', strtotime('-1 week'));
                break;
            case 'month':
                $dateFrom = date('Y-m-d', strtotime('-1 month'));
                break;
            case 'quarter':
                $dateFrom = date('Y-m-d', strtotime('-3 months'));
                break;
            case 'year':
                $dateFrom = date('Y-m-d', strtotime('-1 year'));
                break;
        }
        $dateTo = date('Y-m-d');

        // Estadísticas de envíos en el periodo
        $shipmentQuery = "SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status = 'en_transito' THEN 1 ELSE 0 END) as in_transit_count,
            SUM(CASE WHEN status = 'pendiente' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(shipping_cost) as total_revenue
            FROM shipments
            WHERE created_at BETWEEN :date_from AND :date_to";

        $stmt = $db->prepare($shipmentQuery);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $resultData->shipments = $stmt->fetch(\PDO::FETCH_OBJ);

        // Estadísticas de facturación en el periodo
        $invoiceQuery = "SELECT 
            COUNT(*) as total_count,
            SUM(total) as total_amount,
            SUM(CASE WHEN status = 'pagada' THEN total ELSE 0 END) as paid_amount,
            SUM(CASE WHEN status = 'pendiente' THEN total ELSE 0 END) as pending_amount,
            COUNT(CASE WHEN status = 'pagada' THEN id END) as paid_count,
            COUNT(CASE WHEN status = 'pendiente' THEN id END) as pending_count
            FROM invoices
            WHERE issue_date BETWEEN :date_from AND :date_to";

        $stmt = $db->prepare($invoiceQuery);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $resultData->invoices = $stmt->fetch(\PDO::FETCH_OBJ);

        // Clientes más activos en el periodo
        $clientQuery = "SELECT 
            c.id,
            c.business_name,
            COUNT(s.id) as shipment_count,
            SUM(s.shipping_cost) as total_revenue
            FROM clients c
            JOIN shipments s ON c.id = s.client_id
            WHERE s.created_at BETWEEN :date_from AND :date_to
            GROUP BY c.id, c.business_name
            ORDER BY shipment_count DESC
            LIMIT 10";

        $stmt = $db->prepare($clientQuery);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $resultData->topClients = $stmt->fetchAll(\PDO::FETCH_OBJ);

        // Conductores más activos en el periodo
        $driverQuery = "SELECT 
            u.id,
            CONCAT(u.firstname, ' ', u.lastname) as driver_name,
            COUNT(s.id) as shipment_count,
            SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
            SUM(s.shipping_cost) as total_revenue,
            ROUND((SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) / COUNT(s.id)) * 100, 2) as completion_rate
            FROM users u
            JOIN shipments s ON u.id = s.driver_id
            WHERE s.created_at BETWEEN :date_from AND :date_to AND u.role = 'transportista'
            GROUP BY u.id, driver_name
            ORDER BY shipment_count DESC
            LIMIT 10";

        $stmt = $db->prepare($driverQuery);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $resultData->topDrivers = $stmt->fetchAll(\PDO::FETCH_OBJ);

        // Distribución por día de la semana
        $dayOfWeekQuery = "SELECT 
            DAYOFWEEK(created_at) as day_number,
            DAYNAME(created_at) as day_name,
            COUNT(*) as count
            FROM shipments
            WHERE created_at BETWEEN :date_from AND :date_to
            GROUP BY day_number, day_name
            ORDER BY day_number";

        $stmt = $db->prepare($dayOfWeekQuery);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $resultData->shipmentsByDayOfWeek = $stmt->fetchAll(\PDO::FETCH_OBJ);

        // Rutas más frecuentes
        $routesQuery = "SELECT 
            origin_address,
            destination_address,
            COUNT(*) as count,
            SUM(shipping_cost) as total_revenue
            FROM shipments
            WHERE created_at BETWEEN :date_from AND :date_to
            GROUP BY origin_address, destination_address
            ORDER BY count DESC
            LIMIT 10";

        $stmt = $db->prepare($routesQuery);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $resultData->topRoutes = $stmt->fetchAll(\PDO::FETCH_OBJ);

        // Progreso mensual (si el período es trimestre o año)
        if ($period == 'quarter' || $period == 'year') {
            $monthlyProgressQuery = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as shipment_count,
                SUM(shipping_cost) as total_revenue
                FROM shipments
                WHERE created_at BETWEEN :date_from AND :date_to
                GROUP BY month
                ORDER BY month";

            $stmt = $db->prepare($monthlyProgressQuery);
            $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
            $resultData->monthlyProgress = $stmt->fetchAll(\PDO::FETCH_OBJ);
        }

        $responseObj = new \stdClass();
        $responseObj->ok = true;
        $responseObj->msg = "Estadísticas del período obtenidas correctamente";
        $responseObj->data = $resultData;
        $responseObj->period = [
            'name' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ];
    } catch (\Exception $e) {
        $responseObj = new \stdClass();
        $responseObj->ok = false;
        $responseObj->msg = $e->getMessage();
        $responseObj->data = null;
    }

    $response->getBody()->write(json_encode($responseObj));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($responseObj->ok ? 200 : 500);
});

// Obtener comparativa entre dos períodos
$app->post("/statistics/compare", function (Request $request, Response $response, array $args) {
    $params = $request->getParsedBody();

    $requiredParams = ['period1_from', 'period1_to', 'period2_from', 'period2_to'];
    foreach ($requiredParams as $param) {
        if (!isset($params[$param]) || empty($params[$param])) {
            $result = new \stdClass();
            $result->ok = false;
            $result->msg = "Faltan parámetros requeridos. Se necesitan: " . implode(', ', $requiredParams);
            $result->data = null;

            $response->getBody()->write(json_encode($result));
            return $response
                ->withHeader("Content-Type", "application/json")
                ->withStatus(400);
        }
    }

    $db = $this->get("db");
    $result = new \stdClass();

    try {
        // Función para obtener estadísticas de un período
        $getPeriodStats = function ($dateFrom, $dateTo) use ($db) {
            $stats = new \stdClass();

            // Estadísticas de envíos
            $shipmentQuery = "SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'en_transito' THEN 1 ELSE 0 END) as in_transit_count,
                SUM(CASE WHEN status = 'pendiente' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(shipping_cost) as total_revenue,
                AVG(shipping_cost) as avg_revenue
                FROM shipments
                WHERE created_at BETWEEN :date_from AND :date_to";

            $stmt = $db->prepare($shipmentQuery);
            $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
            $stats->shipments = $stmt->fetch(\PDO::FETCH_OBJ);

            // Estadísticas de facturación
            $invoiceQuery = "SELECT 
                COUNT(*) as total_count,
                SUM(total) as total_amount,
                SUM(CASE WHEN status = 'pagada' THEN total ELSE 0 END) as paid_amount,
                SUM(CASE WHEN status = 'pendiente' THEN total ELSE 0 END) as pending_amount,
                COUNT(CASE WHEN status = 'pagada' THEN id END) as paid_count,
                COUNT(CASE WHEN status = 'pendiente' THEN id END) as pending_count,
                AVG(total) as avg_amount
                FROM invoices
                WHERE issue_date BETWEEN :date_from AND :date_to";

            $stmt = $db->prepare($invoiceQuery);
            $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
            $stats->invoices = $stmt->fetch(\PDO::FETCH_OBJ);

            // Top 5 clientes
            $topClientsQuery = "SELECT 
                c.id,
                c.business_name,
                COUNT(s.id) as shipment_count,
                SUM(s.shipping_cost) as total_revenue
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                WHERE s.created_at BETWEEN :date_from AND :date_to
                GROUP BY c.id, c.business_name
                ORDER BY shipment_count DESC
                LIMIT 5";

            $stmt = $db->prepare($topClientsQuery);
            $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
            $stats->topClients = $stmt->fetchAll(\PDO::FETCH_OBJ);

            // Top 5 conductores
            $topDriversQuery = "SELECT 
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) as driver_name,
                COUNT(s.id) as shipment_count,
                SUM(s.shipping_cost) as total_revenue,
                ROUND((SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) / COUNT(s.id)) * 100, 2) as completion_rate
                FROM users u
                JOIN shipments s ON u.id = s.driver_id
                WHERE s.created_at BETWEEN :date_from AND :date_to AND u.role = 'transportista'
                GROUP BY u.id, driver_name
                ORDER BY shipment_count DESC
                LIMIT 5";

            $stmt = $db->prepare($topDriversQuery);
            $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
            $stats->topDrivers = $stmt->fetchAll(\PDO::FETCH_OBJ);

            return $stats;
        };

        // Obtener estadísticas de ambos períodos
        $period1 = $getPeriodStats($params['period1_from'], $params['period1_to']);
        $period2 = $getPeriodStats($params['period2_from'], $params['period2_to']);

        // Calcular diferencias y porcentajes
        $getDiff = function ($value1, $value2) {
            if (!$value1) return [
                'diff' => $value2,
                'percentage' => $value2 ? 100 : 0
            ];

            $diff = $value2 - $value1;
            $percentage = $value1 != 0 ? round(($diff / $value1) * 100, 2) : 0;

            return [
                'diff' => $diff,
                'percentage' => $percentage
            ];
        };

        // Calcular comparativas
        $comparison = new \stdClass();

        // Envíos
        $comparison->shipments = new \stdClass();
        $comparison->shipments->total_count = $getDiff(
            $period1->shipments->total_count,
            $period2->shipments->total_count
        );
        $comparison->shipments->completed_count = $getDiff(
            $period1->shipments->completed_count,
            $period2->shipments->completed_count
        );
        $comparison->shipments->total_revenue = $getDiff(
            $period1->shipments->total_revenue,
            $period2->shipments->total_revenue
        );
        $comparison->shipments->avg_revenue = $getDiff(
            $period1->shipments->avg_revenue,
            $period2->shipments->avg_revenue
        );

        // Facturas
        $comparison->invoices = new \stdClass();
        $comparison->invoices->total_count = $getDiff(
            $period1->invoices->total_count,
            $period2->invoices->total_count
        );
        $comparison->invoices->total_amount = $getDiff(
            $period1->invoices->total_amount,
            $period2->invoices->total_amount
        );
        $comparison->invoices->paid_amount = $getDiff(
            $period1->invoices->paid_amount,
            $period2->invoices->paid_amount
        );
        $comparison->invoices->avg_amount = $getDiff(
            $period1->invoices->avg_amount,
            $period2->invoices->avg_amount
        );

        $responseObj = new \stdClass();
        $responseObj->ok = true;
        $responseObj->msg = "Comparativa entre períodos obtenida correctamente";
        $responseObj->data = [
            'period1' => [
                'from' => $params['period1_from'],
                'to' => $params['period1_to'],
                'stats' => $period1
            ],
            'period2' => [
                'from' => $params['period2_from'],
                'to' => $params['period2_to'],
                'stats' => $period2
            ],
            'comparison' => $comparison
        ];
    } catch (\Exception $e) {
        $responseObj = new \stdClass();
        $responseObj->ok = false;
        $responseObj->msg = $e->getMessage();
        $responseObj->data = null;
    }

    $response->getBody()->write(json_encode($responseObj));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($responseObj->ok ? 200 : 500);
});

// Obtener proyecciones basadas en datos históricos
$app->get("/statistics/projections", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $months = isset($params['months']) ? intval($params['months']) : 3; // Proyección para los próximos 3 meses por defecto

    if ($months <= 0 || $months > 12) {
        $result = new \stdClass();
        $result->ok = false;
        $result->msg = "El parámetro 'months' debe estar entre 1 y 12";
        $result->data = null;

        $response->getBody()->write(json_encode($result));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(400);
    }

    $db = $this->get("db");

    try {
        // Obtener datos históricos de los últimos 6 meses para hacer proyecciones
        $historicalShipments = "SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count,
            SUM(shipping_cost) as revenue,
            COUNT(CASE WHEN status = 'entregado' THEN 1 END) as completed_count
            FROM shipments
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY month
            ORDER BY month";

        $stmt = $db->prepare($historicalShipments);
        $stmt->execute();
        $shipmentHistory = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $historicalInvoices = "SELECT 
            DATE_FORMAT(issue_date, '%Y-%m') as month,
            COUNT(*) as count,
            SUM(total) as amount,
            COUNT(CASE WHEN status = 'pagada' THEN 1 END) as paid_count
            FROM invoices
            WHERE issue_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY month
            ORDER BY month";

        $stmt = $db->prepare($historicalInvoices);
        $stmt->execute();
        $invoiceHistory = $stmt->fetchAll(\PDO::FETCH_OBJ);

        // Calcular promedios para hacer proyecciones
        $shipmentTotal = 0;
        $shipmentRevenue = 0;
        $shipmentCompleted = 0;
        $invoiceTotal = 0;
        $invoiceAmount = 0;
        $invoicePaid = 0;

        foreach ($shipmentHistory as $sh) {
            $shipmentTotal += $sh->count;
            $shipmentRevenue += $sh->revenue;
            $shipmentCompleted += $sh->completed_count;
        }

        foreach ($invoiceHistory as $inv) {
            $invoiceTotal += $inv->count;
            $invoiceAmount += $inv->amount;
            $invoicePaid += $inv->paid_count;
        }

        $avgShipmentCount = count($shipmentHistory) > 0 ? $shipmentTotal / count($shipmentHistory) : 0;
        $avgShipmentRevenue = count($shipmentHistory) > 0 ? $shipmentRevenue / count($shipmentHistory) : 0;
        $completionRate = $shipmentTotal > 0 ? ($shipmentCompleted / $shipmentTotal) * 100 : 0;

        $avgInvoiceCount = count($invoiceHistory) > 0 ? $invoiceTotal / count($invoiceHistory) : 0;
        $avgInvoiceAmount = count($invoiceHistory) > 0 ? $invoiceAmount / count($invoiceHistory) : 0;
        $paymentRate = $invoiceTotal > 0 ? ($invoicePaid / $invoiceTotal) * 100 : 0;

        // Aplicar tendencia basada en los últimos 3 meses para proyecciones
        $trendFactor = 1.0; // Factor de crecimiento por defecto

        if (count($shipmentHistory) >= 3) {
            $last3Months = array_slice($shipmentHistory, -3);
            $firstMonth = $last3Months[0]->count;
            $lastMonth = $last3Months[2]->count;

            if ($firstMonth > 0) {
                $monthlyGrowth = ($lastMonth - $firstMonth) / $firstMonth;
                $trendFactor = 1 + $monthlyGrowth;

                // Limitar el factor a un rango razonable
                $trendFactor = max(0.9, min($trendFactor, 1.3));
            }
        }

        // Generar proyecciones para los próximos meses
        $projections = [];
        $currentMonth = date('Y-m');
        $projectedCount = $avgShipmentCount;
        $projectedRevenue = $avgShipmentRevenue;
        $projectedInvoiceCount = $avgInvoiceCount;
        $projectedInvoiceAmount = $avgInvoiceAmount;

        for ($i = 1; $i <= $months; $i++) {
            $projectedMonth = date('Y-m', strtotime("$currentMonth +$i months"));

            // Aplica el factor de crecimiento para cada mes
            $projectedCount *= $trendFactor;
            $projectedRevenue *= $trendFactor;
            $projectedInvoiceCount *= $trendFactor;
            $projectedInvoiceAmount *= $trendFactor;

            $projections[] = [
                'month' => $projectedMonth,
                'shipments' => [
                    'projected_count' => round($projectedCount),
                    'projected_revenue' => round($projectedRevenue, 2),
                    'projected_completed' => round($projectedCount * ($completionRate / 100))
                ],
                'invoices' => [
                    'projected_count' => round($projectedInvoiceCount),
                    'projected_amount' => round($projectedInvoiceAmount, 2),
                    'projected_paid' => round($projectedInvoiceCount * ($paymentRate / 100))
                ]
            ];
        }

        $responseObj = new \stdClass();
        $responseObj->ok = true;
        $responseObj->msg = "Proyecciones obtenidas correctamente";
        $responseObj->data = [
            'historical' => [
                'shipments' => $shipmentHistory,
                'invoices' => $invoiceHistory
            ],
            'averages' => [
                'shipments' => [
                    'avg_count' => round($avgShipmentCount, 2),
                    'avg_revenue' => round($avgShipmentRevenue, 2),
                    'completion_rate' => round($completionRate, 2)
                ],
                'invoices' => [
                    'avg_count' => round($avgInvoiceCount, 2),
                    'avg_amount' => round($avgInvoiceAmount, 2),
                    'payment_rate' => round($paymentRate, 2)
                ],
                'trend_factor' => round($trendFactor, 4)
            ],
            'projections' => $projections
        ];
    } catch (\Exception $e) {
        $responseObj = new \stdClass();
        $responseObj->ok = false;
        $responseObj->msg = $e->getMessage();
        $responseObj->data = null;
    }

    $response->getBody()->write(json_encode($responseObj));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($responseObj->ok ? 200 : 500);
});

// Exportar estadísticas a CSV
$app->get("/statistics/export/{type}", function (Request $request, Response $response, array $args) {
    $type = $args['type'];
    $params = $request->getQueryParams();

    $validTypes = ['shipments', 'invoices', 'clients', 'drivers', 'general', 'top', 'financials'];
    if (!in_array($type, $validTypes)) {
        $result = new \stdClass();
        $result->ok = false;
        $result->msg = "Tipo no válido. Use: " . implode(', ', $validTypes);
        $result->data = null;

        $response->getBody()->write(json_encode($result));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(400);
    }

    $db = $this->get("db");
    $stats = new Statistics($db);

    try {
        $data = [];
        $filename = '';
        $output = '';

        // Preparar las cláusulas WHERE para filtrado por fecha
        $whereClause = "WHERE 1=1";
        $params_sql = [];

        if (isset($params['date_from']) && !empty($params['date_from'])) {
            $whereClause .= " AND created_at >= :date_from";
            $params_sql['date_from'] = $params['date_from'];
        }

        if (isset($params['date_to']) && !empty($params['date_to'])) {
            $whereClause .= " AND created_at <= :date_to";
            $params_sql['date_to'] = $params['date_to'];
        }

        switch ($type) {
            case 'shipments':
                $result = $stats->getShipmentStatistics($params)->getResult();
                if ($result->ok) {
                    $filename = 'estadisticas_envios.csv';

                    // Resumen por estado
                    $output = "RESUMEN DE ENVÍOS\n\n";
                    if (isset($result->data->statusSummary)) {
                        $summary = $result->data->statusSummary;
                        $output .= "Total Envíos,{$summary->total}\n";
                        $output .= "Pendientes,{$summary->pendientes}\n";
                        $output .= "En Tránsito,{$summary->en_transito}\n";
                        $output .= "Entregados,{$summary->entregados}\n";
                        $output .= "Cancelados,{$summary->cancelados}\n";
                        $output .= "Ingresos Totales,{$summary->total_revenue}\n\n";
                    }

                    // Envíos por mes
                    if (isset($result->data->shipmentsByMonth) && !empty($result->data->shipmentsByMonth)) {
                        $output .= "ENVÍOS POR MES\n";
                        $output .= "Mes,Cantidad,Ingreso Total\n";
                        foreach ($result->data->shipmentsByMonth as $row) {
                            $output .= "{$row->month},{$row->count},{$row->total_revenue}\n";
                        }
                        $output .= "\n";
                    }

                    // Top conductores
                    if (isset($result->data->topDrivers) && !empty($result->data->topDrivers)) {
                        $output .= "TOP CONDUCTORES\n";
                        $output .= "Conductor,Cantidad Envíos,Completados,Ingreso Total,Tasa Finalización %\n";
                        foreach ($result->data->topDrivers as $row) {
                            $output .= "{$row->driver_name},{$row->count},{$row->completed_count},{$row->total_revenue},{$row->completion_rate}\n";
                        }
                        $output .= "\n";
                    }

                    // Top clientes
                    if (isset($result->data->topClients) && !empty($result->data->topClients)) {
                        $output .= "TOP CLIENTES\n";
                        $output .= "Cliente,Cantidad Envíos,Ingreso Total\n";
                        foreach ($result->data->topClients as $row) {
                            $output .= "{$row->client_name},{$row->count},{$row->total_revenue}\n";
                        }
                        $output .= "\n";
                    }

                    // Top rutas
                    if (isset($result->data->topRoutes) && !empty($result->data->topRoutes)) {
                        $output .= "RUTAS MÁS FRECUENTES\n";
                        $output .= "Origen,Destino,Cantidad,Ingreso Total\n";
                        foreach ($result->data->topRoutes as $row) {
                            $output .= "\"{$row->origin_address}\",\"{$row->destination_address}\",{$row->count},{$row->total_revenue}\n";
                        }
                    }
                }
                break;

            case 'invoices':
                $result = $stats->getInvoiceStatistics($params)->getResult();
                if ($result->ok) {
                    $filename = 'estadisticas_facturas.csv';

                    // Resumen por estado
                    $output = "RESUMEN DE FACTURACIÓN\n\n";
                    if (isset($result->data->statusSummary)) {
                        $summary = $result->data->statusSummary;
                        $output .= "Total Facturas,{$summary->total}\n";
                        $output .= "Pendientes,{$summary->pendientes}\n";
                        $output .= "Pagadas,{$summary->pagadas}\n";
                        $output .= "Vencidas,{$summary->vencidas}\n";
                        $output .= "Canceladas,{$summary->canceladas}\n";
                        $output .= "Monto Total,{$summary->total_amount}\n";
                        $output .= "Monto Pagado,{$summary->total_paid}\n";
                        $output .= "Monto Pendiente,{$summary->total_pending}\n\n";
                    }

                    // Facturas por mes
                    if (isset($result->data->invoicesByMonth) && !empty($result->data->invoicesByMonth)) {
                        $output .= "FACTURAS POR MES\n";
                        $output .= "Mes,Cantidad,Monto Total,Monto Pagado\n";
                        foreach ($result->data->invoicesByMonth as $row) {
                            $output .= "{$row->month},{$row->count},{$row->total_amount},{$row->paid_amount}\n";
                        }
                        $output .= "\n";
                    }

                    // Top clientes
                    if (isset($result->data->topClients) && !empty($result->data->topClients)) {
                        $output .= "TOP CLIENTES POR FACTURACIÓN\n";
                        $output .= "Cliente,Cantidad Facturas,Monto Total,Monto Pagado,Monto Pendiente\n";
                        foreach ($result->data->topClients as $row) {
                            $output .= "{$row->customer},{$row->count},{$row->total_amount},{$row->paid_amount},{$row->pending_amount}\n";
                        }
                        $output .= "\n";
                    }

                    // Estadísticas de montos
                    if (isset($result->data->amountStats)) {
                        $output .= "ESTADÍSTICAS DE MONTOS\n";
                        $output .= "Monto Promedio,{$result->data->amountStats->average_total}\n";
                        $output .= "Monto Mínimo,{$result->data->amountStats->min_total}\n";
                        $output .= "Monto Máximo,{$result->data->amountStats->max_total}\n";
                        $output .= "Plazo Promedio (días),{$result->data->amountStats->avg_payment_term}\n\n";
                    }

                    // Estadísticas de pagos
                    if (isset($result->data->paymentTiming)) {
                        $output .= "ESTADÍSTICAS DE PAGOS\n";
                        $output .= "Total Pagadas,{$result->data->paymentTiming->total_paid}\n";
                        $output .= "Pagadas a Tiempo,{$result->data->paymentTiming->paid_on_time}\n";
                        $output .= "Pagadas con Retraso,{$result->data->paymentTiming->paid_late}\n";
                        $output .= "Días Promedio de Retraso,{$result->data->paymentTiming->avg_days_late}\n";
                    }
                }
                break;

            case 'clients':
                $result = $stats->getClientStatistics($params)->getResult();
                if ($result->ok) {
                    $filename = 'estadisticas_clientes.csv';

                    // Clientes más activos
                    $output = "TOP CLIENTES POR ACTIVIDAD\n";
                    $output .= "Cliente,Cantidad Envíos,Ingreso Total,Último Envío\n";
                    if (isset($result->data->topActiveClients) && !empty($result->data->topActiveClients)) {
                        foreach ($result->data->topActiveClients as $row) {
                            $output .= "{$row->business_name},{$row->shipment_count},{$row->total_revenue},{$row->last_shipment_date}\n";
                        }
                    }
                    $output .= "\n";

                    // Clientes por facturación
                    $output .= "TOP CLIENTES POR FACTURACIÓN\n";
                    $output .= "Cliente,Monto Total,Cantidad Facturas,Monto Pagado,Monto Pendiente\n";
                    if (isset($result->data->topClientsByBilling) && !empty($result->data->topClientsByBilling)) {
                        foreach ($result->data->topClientsByBilling as $row) {
                            $output .= "{$row->business_name},{$row->total_billed},{$row->invoice_count},{$row->total_paid},{$row->total_pending}\n";
                        }
                    }
                    $output .= "\n";

                    // Clientes por tasa de pago
                    $output .= "TOP CLIENTES POR TASA DE PAGO\n";
                    $output .= "Cliente,Cantidad Facturas,Facturas Pagadas,Tasa de Pago %,Monto Total\n";
                    if (isset($result->data->topClientsByPaymentRate) && !empty($result->data->topClientsByPaymentRate)) {
                        foreach ($result->data->topClientsByPaymentRate as $row) {
                            $output .= "{$row->business_name},{$row->invoice_count},{$row->paid_count},{$row->payment_rate},{$row->total_billed}\n";
                        }
                    }
                    $output .= "\n";

                    // Clientes con pagos pendientes
                    $output .= "CLIENTES CON PAGOS PENDIENTES\n";
                    $output .= "Cliente,Monto Pendiente,Cantidad Facturas Pendientes,Próximo Vencimiento\n";
                    if (isset($result->data->clientsWithPending) && !empty($result->data->clientsWithPending)) {
                        foreach ($result->data->clientsWithPending as $row) {
                            $output .= "{$row->business_name},{$row->pending_amount},{$row->pending_count},{$row->next_due_date}\n";
                        }
                    }
                }
                break;

            case 'drivers':
                $result = $stats->getDriverStatistics($params)->getResult();
                if ($result->ok) {
                    $filename = 'estadisticas_conductores.csv';

                    // Resumen general
                    $output = "RESUMEN GENERAL DE CONDUCTORES\n\n";
                    if (isset($result->data->driverSummary)) {
                        $summary = $result->data->driverSummary;
                        $output .= "Total Conductores Activos,{$summary->total_active_drivers}\n";
                        $output .= "Promedio Envíos por Conductor,{$summary->avg_shipments_per_driver}\n";
                        $output .= "Máximo Envíos por Conductor,{$summary->max_shipments_by_driver}\n";
                        $output .= "Tasa de Finalización Promedio %,{$summary->avg_completion_rate}\n\n";
                    }

                    // Rendimiento por conductor
                    $output .= "RENDIMIENTO POR CONDUCTOR\n";
                    $output .= "Conductor,Total Envíos,Completados,En Tránsito,Pendientes,Cancelados,Ingreso Total,Tasa Finalización %\n";
                    if (isset($result->data->driverPerformance) && !empty($result->data->driverPerformance)) {
                        foreach ($result->data->driverPerformance as $row) {
                            $output .= "{$row->driver_name},{$row->shipment_count},{$row->completed_count},{$row->in_transit_count},{$row->pending_count},{$row->cancelled_count},{$row->total_revenue},{$row->completion_rate}\n";
                        }
                    }
                    $output .= "\n";

                    // Top conductores por ingresos
                    $output .= "TOP CONDUCTORES POR INGRESOS\n";
                    $output .= "Conductor,Ingreso Total,Cantidad Envíos,Ingreso Promedio por Envío\n";
                    if (isset($result->data->topDriversByRevenue) && !empty($result->data->topDriversByRevenue)) {
                        foreach ($result->data->topDriversByRevenue as $row) {
                            $output .= "{$row->driver_name},{$row->total_revenue},{$row->shipment_count},{$row->avg_revenue_per_shipment}\n";
                        }
                    }
                    $output .= "\n";

                    // Eficiencia de conductores
                    $output .= "EFICIENCIA DE CONDUCTORES\n";
                    $output .= "Conductor,Días Trabajados,Total Envíos,Envíos por Día\n";
                    if (isset($result->data->driverEfficiency) && !empty($result->data->driverEfficiency)) {
                        foreach ($result->data->driverEfficiency as $row) {
                            $output .= "{$row->driver_name},{$row->working_days},{$row->total_shipments},{$row->shipments_per_day}\n";
                        }
                    }

                    // Si hay un conductor específico, mostrar su distribución por estado
                    if (isset($result->data->driverStatusDistribution)) {
                        $output .= "\nDISTRIBUCIÓN POR ESTADO PARA CONDUCTOR ESPECÍFICO\n";
                        $output .= "Estado,Cantidad,Ingreso\n";
                        foreach ($result->data->driverStatusDistribution as $row) {
                            $output .= "{$row->status},{$row->count},{$row->total_revenue}\n";
                        }
                    }
                }
                break;

            case 'general':
                $result = $stats->getGeneralStatistics($params)->getResult();
                if ($result->ok) {
                    $filename = 'estadisticas_generales.csv';

                    // Envíos por estado
                    $output = "ENVÍOS POR ESTADO\n";
                    $output .= "Estado,Cantidad\n";
                    if (isset($result->data->shipmentsByStatus) && !empty($result->data->shipmentsByStatus)) {
                        foreach ($result->data->shipmentsByStatus as $row) {
                            $output .= "{$row->status},{$row->count}\n";
                        }
                    }
                    $output .= "\n";

                    // Facturas por estado
                    $output .= "FACTURAS POR ESTADO\n";
                    $output .= "Estado,Cantidad\n";
                    if (isset($result->data->invoicesByStatus) && !empty($result->data->invoicesByStatus)) {
                        foreach ($result->data->invoicesByStatus as $row) {
                            $output .= "{$row->status},{$row->count}\n";
                        }
                    }
                    $output .= "\n";

                    // Estadísticas financieras
                    $output .= "ESTADÍSTICAS FINANCIERAS\n";
                    if (isset($result->data->financialStats)) {
                        $fin = $result->data->financialStats;
                        $output .= "Total Facturado,{$fin->total_invoiced}\n";
                        $output .= "Total Cobrado,{$fin->total_collected}\n";
                        $output .= "Total Pendiente,{$fin->total_pending}\n";
                    }
                    $output .= "\n";

                    // Top clientes
                    $output .= "TOP CLIENTES\n";
                    $output .= "Cliente,Cantidad Envíos,Ingreso Total\n";
                    if (isset($result->data->topClients) && !empty($result->data->topClients)) {
                        foreach ($result->data->topClients as $row) {
                            $output .= "{$row->business_name},{$row->shipment_count},{$row->total_revenue}\n";
                        }
                    }
                    $output .= "\n";

                    // Top conductores
                    $output .= "TOP CONDUCTORES\n";
                    $output .= "Conductor,Cantidad Envíos,Completados,Ingreso Total,Tasa Finalización %\n";
                    if (isset($result->data->topDrivers) && !empty($result->data->topDrivers)) {
                        foreach ($result->data->topDrivers as $row) {
                            $output .= "{$row->driver_name},{$row->shipment_count},{$row->completed_count},{$row->total_revenue},{$row->completion_rate}\n";
                        }
                    }
                    $output .= "\n";

                    // Tendencia mensual
                    $output .= "TENDENCIA MENSUAL\n";
                    $output .= "Mes,Cantidad Envíos,Ingreso Total\n";
                    if (isset($result->data->monthlyTrend) && !empty($result->data->monthlyTrend)) {
                        foreach ($result->data->monthlyTrend as $row) {
                            $output .= "{$row->month},{$row->shipment_count},{$row->total_revenue}\n";
                        }
                    }
                }
                break;

            case 'top':
                $result = $stats->getTopPerformers($params)->getResult();
                if ($result->ok) {
                    $filename = 'top_performers.csv';

                    // Top clientes por envíos
                    $output = "TOP CLIENTES POR ENVÍOS\n";
                    $output .= "Cliente,Cantidad Envíos,Ingreso Total\n";
                    if (isset($result->data->topClientsByShipments) && !empty($result->data->topClientsByShipments)) {
                        foreach ($result->data->topClientsByShipments as $row) {
                            $output .= "{$row->business_name},{$row->shipment_count},{$row->total_revenue}\n";
                        }
                    }
                    $output .= "\n";

                    // Top clientes por facturación
                    $output .= "TOP CLIENTES POR FACTURACIÓN\n";
                    $output .= "Cliente,Monto Total,Cantidad Facturas,Monto Pagado,Monto Pendiente\n";
                    if (isset($result->data->topClientsByBilling) && !empty($result->data->topClientsByBilling)) {
                        foreach ($result->data->topClientsByBilling as $row) {
                            $output .= "{$row->business_name},{$row->total_billed},{$row->invoice_count},{$row->total_paid},{$row->total_pending}\n";
                        }
                    }
                    $output .= "\n";

                    // Top conductores por envíos
                    $output .= "TOP CONDUCTORES POR ENVÍOS\n";
                    $output .= "Conductor,Cantidad Envíos,Completados,Ingreso Total,Tasa Finalización %\n";
                    if (isset($result->data->topDriversByShipments) && !empty($result->data->topDriversByShipments)) {
                        foreach ($result->data->topDriversByShipments as $row) {
                            $output .= "{$row->driver_name},{$row->shipment_count},{$row->completed_count},{$row->total_revenue},{$row->completion_rate}\n";
                        }
                    }
                    $output .= "\n";

                    // Top conductores por ingresos
                    $output .= "TOP CONDUCTORES POR INGRESOS\n";
                    $output .= "Conductor,Ingreso Total,Cantidad Envíos,Ingreso Promedio por Envío\n";
                    if (isset($result->data->topDriversByRevenue) && !empty($result->data->topDriversByRevenue)) {
                        foreach ($result->data->topDriversByRevenue as $row) {
                            $output .= "{$row->driver_name},{$row->total_revenue},{$row->shipment_count},{$row->avg_revenue_per_shipment}\n";
                        }
                    }
                    $output .= "\n";

                    // Top rutas
                    $output .= "TOP RUTAS\n";
                    $output .= "Origen,Destino,Cantidad,Ingreso Total,Ingreso Promedio\n";
                    if (isset($result->data->topRoutes) && !empty($result->data->topRoutes)) {
                        foreach ($result->data->topRoutes as $row) {
                            $output .= "\"{$row->origin_address}\",\"{$row->destination_address}\",{$row->count},{$row->total_revenue},{$row->avg_revenue}\n";
                        }
                    }
                }
                break;

            case 'financials':
                // Consulta personalizada para estadísticas financieras detalladas
                $whereClauseInvoices = "WHERE 1=1";
                $whereClauseShipments = "WHERE 1=1";
                $queryParams = [];

                if (isset($params['date_from']) && !empty($params['date_from'])) {
                    $whereClauseInvoices .= " AND issue_date >= :date_from";
                    $whereClauseShipments .= " AND created_at >= :date_from";
                    $queryParams['date_from'] = $params['date_from'];
                }

                if (isset($params['date_to']) && !empty($params['date_to'])) {
                    $whereClauseInvoices .= " AND issue_date <= :date_to";
                    $whereClauseShipments .= " AND created_at <= :date_to";
                    $queryParams['date_to'] = $params['date_to'];
                }

                // Resumen financiero general
                $financialSummaryQuery = "SELECT
                    (SELECT SUM(total) FROM invoices " . $whereClauseInvoices . ") as total_invoiced,
                    (SELECT SUM(total) FROM invoices " . $whereClauseInvoices . " AND status = 'pagada') as total_collected,
                    (SELECT SUM(total) FROM invoices " . $whereClauseInvoices . " AND status = 'pendiente') as total_pending,
                    (SELECT COUNT(*) FROM invoices " . $whereClauseInvoices . ") as total_invoices,
                    (SELECT COUNT(*) FROM invoices " . $whereClauseInvoices . " AND status = 'pagada') as paid_invoices,
                    (SELECT SUM(shipping_cost) FROM shipments " . $whereClauseShipments . ") as total_shipping_revenue,
                    (SELECT COUNT(*) FROM shipments " . $whereClauseShipments . ") as total_shipments";

                $stmt = $db->prepare($financialSummaryQuery);
                foreach ($queryParams as $param => $value) {
                    $stmt->bindValue(":$param", $value);
                }
                $stmt->execute();
                $financialSummary = $stmt->fetch(\PDO::FETCH_OBJ);

                // Facturación mensual
                $monthlyRevenueQuery = "SELECT 
                    DATE_FORMAT(issue_date, '%Y-%m') as month,
                    COUNT(*) as invoice_count,
                    SUM(total) as total_amount,
                    SUM(CASE WHEN status = 'pagada' THEN total ELSE 0 END) as paid_amount,
                    SUM(CASE WHEN status = 'pendiente' THEN total ELSE 0 END) as pending_amount
                    FROM invoices
                    " . $whereClauseInvoices . "
                    GROUP BY month
                    ORDER BY month";

                $stmt = $db->prepare($monthlyRevenueQuery);
                foreach ($queryParams as $param => $value) {
                    $stmt->bindValue(":$param", $value);
                }
                $stmt->execute();
                $monthlyRevenue = $stmt->fetchAll(\PDO::FETCH_OBJ);

                // Top clientes por ingresos
                $topClientsByRevenueQuery = "SELECT 
                    c.id,
                    c.business_name,
                    SUM(i.total) as total_amount,
                    COUNT(i.id) as invoice_count,
                    SUM(CASE WHEN i.status = 'pagada' THEN i.total ELSE 0 END) as paid_amount,
                    SUM(CASE WHEN i.status = 'pendiente' THEN i.total ELSE 0 END) as pending_amount
                    FROM clients c
                    JOIN shipments s ON c.id = s.client_id
                    JOIN invoices i ON s.id = i.shipment_id
                    " . str_replace('WHERE 1=1', 'WHERE i.issue_date IS NOT NULL', $whereClauseInvoices) . "
                    GROUP BY c.id, c.business_name
                    ORDER BY total_amount DESC
                    LIMIT 10";

                $stmt = $db->prepare($topClientsByRevenueQuery);
                foreach ($queryParams as $param => $value) {
                    $stmt->bindValue(":$param", $value);
                }
                $stmt->execute();
                $topClientsByRevenue = $stmt->fetchAll(\PDO::FETCH_OBJ);

                $filename = 'reporte_financiero.csv';

                // Resumen financiero
                $output = "RESUMEN FINANCIERO\n\n";
                $output .= "Total Facturado,{$financialSummary->total_invoiced}\n";
                $output .= "Total Cobrado,{$financialSummary->total_collected}\n";
                $output .= "Total Pendiente,{$financialSummary->total_pending}\n";
                $output .= "Cantidad Facturas,{$financialSummary->total_invoices}\n";
                $output .= "Facturas Pagadas,{$financialSummary->paid_invoices}\n";
                $output .= "Ingreso por Envíos,{$financialSummary->total_shipping_revenue}\n";
                $output .= "Cantidad Envíos,{$financialSummary->total_shipments}\n\n";

                // Facturación mensual
                $output .= "FACTURACIÓN MENSUAL\n";
                $output .= "Mes,Cantidad Facturas,Monto Total,Monto Pagado,Monto Pendiente\n";
                foreach ($monthlyRevenue as $row) {
                    $output .= "{$row->month},{$row->invoice_count},{$row->total_amount},{$row->paid_amount},{$row->pending_amount}\n";
                }
                $output .= "\n";

                // Top clientes por ingresos
                $output .= "TOP CLIENTES POR INGRESOS\n";
                $output .= "Cliente,Monto Total,Cantidad Facturas,Monto Pagado,Monto Pendiente\n";
                foreach ($topClientsByRevenue as $row) {
                    $output .= "{$row->business_name},{$row->total_amount},{$row->invoice_count},{$row->paid_amount},{$row->pending_amount}\n";
                }
                break;
        }

        if (!empty($output) && !empty($filename)) {
            // Devolver como archivo CSV para descarga
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $output);
            rewind($stream);

            return $response
                ->withHeader('Content-Type', 'text/csv')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withBody(new \Slim\Psr7\Stream($stream));
        } else {
            throw new \Exception("No se pudieron generar datos para exportar");
        }
    } catch (\Exception $e) {
        $responseObj = new \stdClass();
        $responseObj->ok = false;
        $responseObj->msg = $e->getMessage();
        $responseObj->data = null;

        $response->getBody()->write(json_encode($responseObj));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(500);
    }
});
