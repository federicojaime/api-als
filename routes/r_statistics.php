<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use objects\Statistics;

// GET - Obtener estadísticas del dashboard con filtros opcionales
$app->get("/statistics/dashboard", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();

    // Obtener la conexión a la base de datos
    $db = $this->get("db");

    // Definir fechas por defecto si no se proporcionan
    if (!isset($params['date_from']) || empty($params['date_from'])) {
        $params['date_from'] = date('Y-m-d', strtotime('-1 month'));
    }

    if (!isset($params['date_to']) || empty($params['date_to'])) {
        $params['date_to'] = date('Y-m-d');
    }

    try {
        // Construir filtros básicos para la tabla shipments
        $filterConditions = "WHERE s.created_at BETWEEN :date_from AND :date_to";
        $queryParams = [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to']
        ];

        // Agregar filtros adicionales
        if (isset($params['client_id']) && !empty($params['client_id'])) {
            $filterConditions .= " AND s.client_id = :client_id";
            $queryParams['client_id'] = $params['client_id'];
        }

        if (isset($params['driver_id']) && !empty($params['driver_id'])) {
            $filterConditions .= " AND s.driver_id = :driver_id";
            $queryParams['driver_id'] = $params['driver_id'];
        }

        if (isset($params['status']) && !empty($params['status']) && $params['status'] !== 'todos') {
            $filterConditions .= " AND s.status = :status";
            $queryParams['status'] = $params['status'];
        }

        // 1. Obtener contadores de envíos por estado
        $countsQuery = "SELECT 
            COUNT(*) as total_shipments,
            SUM(CASE WHEN s.status = 'pendiente' THEN 1 ELSE 0 END) as pending_shipments,
            SUM(CASE WHEN s.status = 'en_transito' THEN 1 ELSE 0 END) as in_transit_shipments,
            SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as delivered_shipments,
            SUM(CASE WHEN s.status = 'cancelado' THEN 1 ELSE 0 END) as cancelled_shipments,
            COUNT(DISTINCT s.client_id) as active_clients,
            COUNT(DISTINCT s.driver_id) as active_drivers,
            SUM(s.shipping_cost) as total_revenue
            FROM shipments s
            {$filterConditions}";

        $stmt = $db->prepare($countsQuery);
        foreach ($queryParams as $param => $value) {
            $stmt->bindValue(":{$param}", $value);
        }
        $stmt->execute();
        $counts = $stmt->fetch(\PDO::FETCH_ASSOC);

        // 2. Obtener distribución por estado
        $statusDistributionQuery = "SELECT 
            s.status,
            COUNT(*) as count
            FROM shipments s
            {$filterConditions}
            GROUP BY s.status";

        $stmt = $db->prepare($statusDistributionQuery);
        foreach ($queryParams as $param => $value) {
            $stmt->bindValue(":{$param}", $value);
        }
        $stmt->execute();
        $statusDistribution = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Calcular porcentajes
        $totalShipments = $counts['total_shipments'] ?: 1; // Evitar división por cero
        foreach ($statusDistribution as &$status) {
            $status['percentage'] = round(($status['count'] / $totalShipments) * 100, 1);
        }

        // 3. Obtener top transportistas (usuarios con rol 'transportista')
        $topDriversQuery = "SELECT 
    COALESCE(u.id, 0) as driver_id,
    COALESCE(CONCAT(u.firstname, ' ', u.lastname), 'Sin asignar') as driver_name,
    COUNT(s.id) as shipment_count,
    SUM(s.shipping_cost) as total_revenue
    FROM shipments s
    LEFT JOIN users u ON s.driver_id = u.id AND u.role = 'transportista'
    {$filterConditions}
    GROUP BY COALESCE(u.id, 0), COALESCE(CONCAT(u.firstname, ' ', u.lastname), 'Sin asignar')
    ORDER BY shipment_count DESC
    LIMIT 5";

        $stmt = $db->prepare($topDriversQuery);
        foreach ($queryParams as $param => $value) {
            $stmt->bindValue(":{$param}", $value);
        }
        $stmt->execute();
        $topDrivers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 4. Obtener top clientes (de la tabla clients)
        $topClientsQuery = "SELECT 
    COALESCE(c.id, 0) as id,
    COALESCE(c.business_name, s.customer) as business_name,
    COUNT(s.id) as shipment_count,
    SUM(s.shipping_cost) as total_revenue
    FROM shipments s
    LEFT JOIN clients c ON s.client_id = c.id
    {$filterConditions}
    GROUP BY COALESCE(c.id, 0), COALESCE(c.business_name, s.customer)
    ORDER BY shipment_count DESC
    LIMIT 5";

        $stmt = $db->prepare($topClientsQuery);
        foreach ($queryParams as $param => $value) {
            $stmt->bindValue(":{$param}", $value);
        }
        $stmt->execute();
        $topClients = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 5. Obtener total clientes activos (tabla clients con status='active')
        $activeClientsQuery = "SELECT COUNT(*) as active_clients_count FROM clients WHERE status = 'active'";
        $stmt = $db->prepare($activeClientsQuery);
        $stmt->execute();
        $activeClientsResult = $stmt->fetch(\PDO::FETCH_ASSOC);
        $activeClientsCount = $activeClientsResult['active_clients_count'];

        // 6. Obtener tasa de entrega exitosa (envíos entregados / total envíos)
        $deliverySuccessRate = 0;
        if ($counts['total_shipments'] > 0) {
            $deliverySuccessRate = round(($counts['delivered_shipments'] / $counts['total_shipments']) * 100, 1);
        }

        // Preparar la respuesta
        $result = [
            'ok' => true,
            'msg' => 'Estadísticas del dashboard obtenidas correctamente',
            'data' => [
                'counts' => [
                    'total_shipments' => (int)$counts['total_shipments'],
                    'pending_shipments' => (int)$counts['pending_shipments'],
                    'in_transit_shipments' => (int)$counts['in_transit_shipments'],
                    'delivered_shipments' => (int)$counts['delivered_shipments'],
                    'cancelled_shipments' => (int)$counts['cancelled_shipments'],
                    'active_clients' => (int)$activeClientsCount,
                    'total_revenue' => (float)$counts['total_revenue'],
                    'delivery_success_rate' => $deliverySuccessRate
                ],
                'status_distribution' => $statusDistribution,
                'top_drivers' => $topDrivers,
                'top_clients' => $topClients
            ]
        ];

        $response->getBody()->write(json_encode($result));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(200);
    } catch (\Exception $e) {
        $resp = [
            'ok' => false,
            'msg' => $e->getMessage(),
            'data' => null
        ];

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(500);
    }
});

// GET - Exportar estadísticas
$app->get("/statistics/export/{type}", function (Request $request, Response $response, array $args) {
    $type = $args['type'];
    $params = $request->getQueryParams();

    // Obtener la conexión a la base de datos
    $db = $this->get("db");

    try {
        // Definir fechas por defecto si no se proporcionan
        if (!isset($params['date_from']) || empty($params['date_from'])) {
            $params['date_from'] = date('Y-m-d', strtotime('-1 month'));
        }

        if (!isset($params['date_to']) || empty($params['date_to'])) {
            $params['date_to'] = date('Y-m-d');
        }

        $whereClause = "WHERE s.created_at BETWEEN :date_from AND :date_to";
        $queryParams = [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to']
        ];

        // Agregar filtros adicionales
        if (isset($params['client_id']) && !empty($params['client_id'])) {
            $whereClause .= " AND s.client_id = :client_id";
            $queryParams['client_id'] = $params['client_id'];
        }

        if (isset($params['driver_id']) && !empty($params['driver_id'])) {
            $whereClause .= " AND s.driver_id = :driver_id";
            $queryParams['driver_id'] = $params['driver_id'];
        }

        if (isset($params['status']) && !empty($params['status']) && $params['status'] !== 'todos') {
            $whereClause .= " AND s.status = :status";
            $queryParams['status'] = $params['status'];
        }

        $csvContent = '';
        $filename = '';

        // Generar contenido CSV según el tipo solicitado
        switch ($type) {
            case 'shipments':
                $query = "SELECT 
                    s.ref_code as 'Código de Referencia',
                    s.customer as 'Cliente',
                    DATE_FORMAT(s.created_at, '%Y-%m-%d') as 'Fecha',
                    s.origin_address as 'Origen',
                    s.destination_address as 'Destino',
                    s.shipping_cost as 'Costo',
                    s.status as 'Estado'
                FROM shipments s
                {$whereClause}
                ORDER BY s.created_at DESC";

                $filename = 'estadisticas_envios_' . date('Y-m-d') . '.csv';
                break;

            case 'clients':
                $query = "SELECT 
                    c.business_name as 'Cliente',
                    COUNT(s.id) as 'Total Envíos',
                    SUM(s.shipping_cost) as 'Ingresos Total',
                    MAX(s.created_at) as 'Último Envío'
                FROM clients c
                JOIN shipments s ON c.id = s.client_id
                {$whereClause}
                GROUP BY c.id, c.business_name
                ORDER BY COUNT(s.id) DESC";

                $filename = 'estadisticas_clientes_' . date('Y-m-d') . '.csv';
                break;

            case 'drivers':
                $query = "SELECT 
                    CONCAT(u.firstname, ' ', u.lastname) as 'Transportista',
                    COUNT(s.id) as 'Total Envíos',
                    SUM(s.shipping_cost) as 'Ingresos Total',
                    COUNT(CASE WHEN s.status = 'entregado' THEN 1 END) as 'Envíos Completados'
                FROM users u
                JOIN shipments s ON u.id = s.driver_id
                {$whereClause}
                AND u.role = 'transportista'
                GROUP BY u.id, u.firstname, u.lastname
                ORDER BY COUNT(s.id) DESC";

                $filename = 'estadisticas_transportistas_' . date('Y-m-d') . '.csv';
                break;

            case 'general':
                $query = "SELECT 
                    s.id as 'ID',
                    s.ref_code as 'Código',
                    s.customer as 'Cliente',
                    CONCAT(u.firstname, ' ', u.lastname) as 'Transportista',
                    s.origin_address as 'Origen',
                    s.destination_address as 'Destino',
                    s.shipping_cost as 'Costo',
                    s.status as 'Estado',
                    DATE_FORMAT(s.created_at, '%Y-%m-%d') as 'Fecha Creación'
                FROM shipments s
                LEFT JOIN users u ON s.driver_id = u.id
                {$whereClause}
                ORDER BY s.created_at DESC";

                $filename = 'estadisticas_generales_' . date('Y-m-d') . '.csv';
                break;

            default:
                throw new \Exception("Tipo de exportación no válido");
        }

        // Ejecutar la consulta
        $stmt = $db->prepare($query);
        foreach ($queryParams as $param => $value) {
            $stmt->bindValue(":{$param}", $value);
        }
        $stmt->execute();
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($data)) {
            $csvContent = "No hay datos para exportar en el periodo seleccionado";
        } else {
            // Crear cabeceras CSV
            $headers = array_keys($data[0]);
            $csvContent = implode(',', $headers) . "\n";

            // Agregar filas de datos
            foreach ($data as $row) {
                $values = array_map(function ($value) {
                    // Escapar comillas y encerrar entre comillas si contiene comas
                    if (strpos($value, ',') !== false) {
                        return '"' . str_replace('"', '""', $value) . '"';
                    }
                    return $value;
                }, array_values($row));

                $csvContent .= implode(',', $values) . "\n";
            }
        }

        // Configurar la respuesta como descarga de archivo CSV
        $response = $response->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        $response->getBody()->write($csvContent);
        return $response;
    } catch (\Exception $e) {
        $resp = [
            'ok' => false,
            'msg' => $e->getMessage(),
            'data' => null
        ];

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(500);
    }
});

// GET - Obtener lista de clientes para filtros
$app->get("/statistics/clients-list", function (Request $request, Response $response, array $args) {
    $db = $this->get("db");

    try {
        $query = "SELECT id, business_name FROM clients WHERE status = 'active' ORDER BY business_name";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $clients = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resp = [
            'ok' => true,
            'msg' => "Lista de clientes obtenida correctamente",
            'data' => $clients
        ];

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(200);
    } catch (\Exception $e) {
        $resp = [
            'ok' => false,
            'msg' => $e->getMessage(),
            'data' => null
        ];

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(500);
    }
});

// GET - Obtener lista de transportistas para filtros
$app->get("/statistics/drivers-list", function (Request $request, Response $response, array $args) {
    $db = $this->get("db");

    try {
        $query = "SELECT id, CONCAT(firstname, ' ', lastname) as name 
                 FROM users 
                 WHERE role = 'transportista' AND active = true 
                 ORDER BY name";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $drivers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resp = [
            'ok' => true,
            'msg' => "Lista de transportistas obtenida correctamente",
            'data' => $drivers
        ];

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(200);
    } catch (\Exception $e) {
        $resp = [
            'ok' => false,
            'msg' => $e->getMessage(),
            'data' => null
        ];

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(500);
    }
});
