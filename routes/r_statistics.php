<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use objects\Statistics;

// GET - Obtener estadísticas generales para el dashboard
$app->get("/statistics/dashboard", function (Request $request, Response $response, array $args) {
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getDashboardStatistics()->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener todas las estadísticas generales
$app->get("/statistics/general", function (Request $request, Response $response, array $args) {
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getGeneralStatistics()->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas de envíos
$app->get("/statistics/shipments", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getShipmentStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas de facturas
$app->get("/statistics/invoices", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getInvoiceStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas de clientes
$app->get("/statistics/clients", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $stats = new Statistics($this->get("db"));
    $resp = $stats->getClientStatistics($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener estadísticas de conductores
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

    $query = "SELECT 
        c.business_name,
        COUNT(i.id) as invoice_count,
        SUM(i.total) as total_billed,
        SUM(CASE WHEN i.status = 'pagada' THEN i.total ELSE 0 END) as total_paid,
        SUM(CASE WHEN i.status = 'pendiente' THEN i.total ELSE 0 END) as total_pending,
        COUNT(CASE WHEN i.status = 'pendiente' THEN i.id END) as pending_count
        FROM clients c
        LEFT JOIN shipments s ON c.id = s.client_id
        LEFT JOIN invoices i ON s.id = i.shipment_id
        WHERE c.id = :client_id
        GROUP BY c.id, c.business_name";

    $stmt = $db->prepare($query);
    $stmt->execute(['client_id' => $clientId]);
    $clientFinancials = $stmt->fetch(\PDO::FETCH_OBJ);

    $result = new \stdClass();
    $result->ok = true;
    $result->msg = "Estadísticas del cliente obtenidas correctamente";
    $result->data = [
        'shipments' => $shipmentStats->data ?? null,
        'financials' => $clientFinancials ?? null
    ];

    $response->getBody()->write(json_encode($result));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus(200);
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
            LIMIT 5";

        $stmt = $db->prepare($clientQuery);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $resultData->topClients = $stmt->fetchAll(\PDO::FETCH_OBJ);

        // Conductores más activos en el periodo
        $driverQuery = "SELECT 
            u.id,
            CONCAT(u.firstname, ' ', u.lastname) as driver_name,
            COUNT(s.id) as shipment_count,
            SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as completed_count,
            SUM(s.shipping_cost) as total_revenue
            FROM users u
            JOIN shipments s ON u.id = s.driver_id
            WHERE s.created_at BETWEEN :date_from AND :date_to AND u.role = 'transportista'
            GROUP BY u.id, driver_name
            ORDER BY shipment_count DESC
            LIMIT 5";

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

        // CORRECCIÓN: Usar una variable diferente para el objeto de respuesta, no reasignar $response
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
                SUM(shipping_cost) as total_revenue
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
                SUM(CASE WHEN status = 'pendiente' THEN total ELSE 0 END) as pending_amount
                FROM invoices
                WHERE issue_date BETWEEN :date_from AND :date_to";

            $stmt = $db->prepare($invoiceQuery);
            $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
            $stats->invoices = $stmt->fetch(\PDO::FETCH_OBJ);

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
            SUM(shipping_cost) as revenue
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
            SUM(total) as amount
            FROM invoices
            WHERE issue_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY month
            ORDER BY month";

        $stmt = $db->prepare($historicalInvoices);
        $stmt->execute();
        $invoiceHistory = $stmt->fetchAll(\PDO::FETCH_OBJ);

        // Calcular promedios para hacer proyecciones simples
        $shipmentTotal = 0;
        $shipmentRevenue = 0;
        $invoiceTotal = 0;
        $invoiceAmount = 0;

        foreach ($shipmentHistory as $sh) {
            $shipmentTotal += $sh->count;
            $shipmentRevenue += $sh->revenue;
        }

        foreach ($invoiceHistory as $inv) {
            $invoiceTotal += $inv->count;
            $invoiceAmount += $inv->amount;
        }

        $avgShipmentCount = count($shipmentHistory) > 0 ? $shipmentTotal / count($shipmentHistory) : 0;
        $avgShipmentRevenue = count($shipmentHistory) > 0 ? $shipmentRevenue / count($shipmentHistory) : 0;
        $avgInvoiceCount = count($invoiceHistory) > 0 ? $invoiceTotal / count($invoiceHistory) : 0;
        $avgInvoiceAmount = count($invoiceHistory) > 0 ? $invoiceAmount / count($invoiceHistory) : 0;

        // Generar proyecciones para los próximos meses
        $projections = [];
        $currentMonth = date('Y-m');

        for ($i = 1; $i <= $months; $i++) {
            $projectedMonth = date('Y-m', strtotime("$currentMonth +$i months"));

            $projections[] = [
                'month' => $projectedMonth,
                'shipments' => [
                    'projected_count' => round($avgShipmentCount),
                    'projected_revenue' => round($avgShipmentRevenue, 2)
                ],
                'invoices' => [
                    'projected_count' => round($avgInvoiceCount),
                    'projected_amount' => round($avgInvoiceAmount, 2)
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
                    'avg_revenue' => round($avgShipmentRevenue, 2)
                ],
                'invoices' => [
                    'avg_count' => round($avgInvoiceCount, 2),
                    'avg_amount' => round($avgInvoiceAmount, 2)
                ]
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

    $validTypes = ['shipments', 'invoices', 'clients', 'drivers', 'general'];
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

        switch ($type) {
            case 'shipments':
                $result = $stats->getShipmentStatistics($params)->getResult();
                if ($result->ok && isset($result->data->shipmentsByMonth)) {
                    $data = $result->data->shipmentsByMonth;
                    $filename = 'shipments_by_month.csv';

                    // Preparar CSV
                    $output = "Mes,Cantidad\n";
                    foreach ($data as $row) {
                        $output .= "{$row->month},{$row->count}\n";
                    }
                }
                break;

            case 'invoices':
                $result = $stats->getInvoiceStatistics($params)->getResult();
                if ($result->ok && isset($result->data->invoicesByMonth)) {
                    $data = $result->data->invoicesByMonth;
                    $filename = 'invoices_by_month.csv';

                    // Preparar CSV
                    $output = "Mes,Cantidad,Monto Total\n";
                    foreach ($data as $row) {
                        $output .= "{$row->month},{$row->count},{$row->total_amount}\n";
                    }
                }
                break;

            case 'clients':
                $result = $stats->getClientStatistics($params)->getResult();
                if ($result->ok && isset($result->data->clientsByBilling)) {
                    $data = $result->data->clientsByBilling;
                    $filename = 'clients_by_billing.csv';

                    // Preparar CSV
                    $output = "Cliente,Monto Facturado,Cantidad de Facturas\n";
                    foreach ($data as $row) {
                        $output .= "{$row->customer},{$row->total_billed},{$row->invoice_count}\n";
                    }
                }
                break;

            case 'drivers':
                $result = $stats->getDriverStatistics($params)->getResult();
                if ($result->ok && isset($result->data->driverPerformance)) {
                    $data = $result->data->driverPerformance;
                    $filename = 'driver_performance.csv';

                    // Preparar CSV
                    $output = "Conductor,Envíos Totales,Envíos Completados,Tasa de Cumplimiento,Ingresos Totales\n";
                    foreach ($data as $row) {
                        $output .= "{$row->driver_name},{$row->shipment_count},{$row->completed_count},{$row->completion_rate}%,{$row->total_revenue}\n";
                    }
                }
                break;

            case 'general':
                $result = $stats->getGeneralStatistics()->getResult();
                if ($result->ok) {
                    $filename = 'general_stats.csv';

                    // Preparar CSV para envíos por estado
                    $output = "ESTADÍSTICAS GENERALES\n\n";
                    $output .= "ENVÍOS POR ESTADO\n";
                    $output .= "Estado,Cantidad\n";

                    foreach ($result->data->shipmentsByStatus as $row) {
                        $output .= "{$row->status},{$row->count}\n";
                    }

                    // Facturas por estado
                    $output .= "\nFACTURAS POR ESTADO\n";
                    $output .= "Estado,Cantidad\n";

                    foreach ($result->data->invoicesByStatus as $row) {
                        $output .= "{$row->status},{$row->count}\n";
                    }

                    // Estadísticas financieras
                    $output .= "\nESTADÍSTICAS FINANCIERAS\n";
                    $fin = $result->data->financialStats;
                    $output .= "Total Facturado,{$fin->total_invoiced}\n";
                    $output .= "Total Cobrado,{$fin->total_collected}\n";
                    $output .= "Total Pendiente,{$fin->total_pending}\n";
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
