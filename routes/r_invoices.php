<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use objects\Invoices;
use utils\Validate;

// GET - Obtener todas las facturas
$app->get("/invoices", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $invoices = new Invoices($this->get("db"));
    $resp = $invoices->getInvoices($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener una factura específica
$app->get("/invoice/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $invoices = new Invoices($this->get("db"));
    $resp = $invoices->getInvoice($args["id"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Crear nueva factura
$app->post("/invoice", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();

    $verificar = [
        "customer" => [
            "type" => "string",
            "min" => 3,
            "max" => 100
        ],
        "issue_date" => [
            "type" => "date"
        ],
        "due_date" => [
            "type" => "date"
        ],
        "items" => [
            "type" => "array",
            "min" => 1
        ]
    ];

    // Validaciones adicionales opcionales
    if (isset($fields["customer_email"])) {
        $verificar["customer_email"] = [
            "type" => "string",
            "isValidMail" => true
        ];
    }

    if (isset($fields["shipment_id"])) {
        $verificar["shipment_id"] = [
            "type" => "number",
            "min" => 1,
            "exist" => "shipments"
        ];
    }

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    $resp = null;

    if ($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $invoices = new Invoices($this->get("db"));
        $resp = $invoices->createInvoice((object)$fields)->getResult();
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// PATCH - Actualizar estado de factura
$app->patch("/invoice/{id:[0-9]+}/status", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();

    $verificar = [
        "status" => [
            "type" => "string",
            "values" => ["pendiente", "pagada", "vencida", "cancelada"]
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    $resp = null;

    if ($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $invoices = new Invoices($this->get("db"));
        $resp = $invoices->updateInvoiceStatus($args["id"], $fields["status"])->getResult();
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Sincronizar con QuickBooks
$app->post("/invoice/{id:[0-9]+}/quickbooks", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();

    $verificar = [
        "quickbooks_id" => [
            "type" => "string",
            "min" => 1
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    $resp = null;

    if ($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $invoices = new Invoices($this->get("db"));
        $resp = $invoices->syncWithQuickBooks($args["id"], $fields["quickbooks_id"])->getResult();
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// DELETE - Eliminar factura
$app->delete("/invoice/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $invoices = new Invoices($this->get("db"));
    $resp = $invoices->deleteInvoice($args["id"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});
