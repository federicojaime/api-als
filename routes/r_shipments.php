<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use objects\Shipments;
use utils\Validate;

// GET - Obtener todos los envíos
$app->get("/shipments", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $shipments = new Shipments($this->get("db"));
    $resp = $shipments->getShipments($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener un envío específico
$app->get("/shipment/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $shipments = new Shipments($this->get("db"));
    $resp = $shipments->getShipment($args["id"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Crear nuevo envío
$app->post("/shipment", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();
    $uploadedFiles = $request->getUploadedFiles();

    $verificar = [
        "customer" => [
            "type" => "string",
            "min" => 3,
            "max" => 100
        ],
        "origin_address" => [
            "type" => "string",
            "min" => 5
        ],
        "destination_address" => [
            "type" => "string",
            "min" => 5
        ],
        "shipping_cost" => [
            "type" => "number",
            "min" => 0
        ],
        "driver_id" => [
            "type" => "number",
            "min" => 1,
            "exist" => "users"
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    if ($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(409);
    }

    // Procesar items
    $items = [];
    if (isset($fields['items'])) {
        $items = json_decode($fields['items'], true);
        if (!is_array($items)) {
            $resp = new \stdClass();
            $resp->ok = false;
            $resp->msg = "Formato de items inválido";
            $response->getBody()->write(json_encode($resp));
            return $response
                ->withHeader("Content-Type", "application/json")
                ->withStatus(409);
        }
    }
    $fields['items'] = $items;

    // Procesar documentos PDF
    $documents = [];
    if (isset($uploadedFiles['documents'])) {
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        foreach ($uploadedFiles['documents'] as $uploadedFile) {
            if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
                $filename = $uploadedFile->getClientFilename();
                $extension = pathinfo($filename, PATHINFO_EXTENSION);

                if (strtolower($extension) !== 'pdf') {
                    continue;
                }

                $basename = bin2hex(random_bytes(8));
                $newFilename = sprintf('%s.%0.8s', $basename, $extension);

                try {
                    $uploadedFile->moveTo($uploadDir . $newFilename);
                    $documents[] = [
                        'name' => $filename,
                        'file_content' => 'uploads/' . $newFilename,
                        'document_type' => 'shipment_doc'
                    ];
                } catch (\Exception $e) {
                    error_log("Error al guardar archivo: " . $e->getMessage());
                    continue;
                }
            }
        }
    }
    $fields['documents'] = $documents;

    $shipments = new Shipments($this->get("db"));
    $resp = $shipments->createShipment((object)$fields)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// PUT - Actualizar envío completo
$app->put("/shipment/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();
    $uploadedFiles = $request->getUploadedFiles();

    $verificar = [
        "customer" => [
            "type" => "string",
            "min" => 3,
            "max" => 100
        ],
        "origin_address" => [
            "type" => "string",
            "min" => 5
        ],
        "destination_address" => [
            "type" => "string",
            "min" => 5
        ],
        "shipping_cost" => [
            "type" => "number",
            "min" => 0
        ],
        "driver_id" => [
            "type" => "number",
            "min" => 1,
            "exist" => "users"
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    if ($validacion->hasErrors()) {
        $response->getBody()->write(json_encode($validacion->getErrors()));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(409);
    }

    // Procesar items
    if (isset($fields['items'])) {
        $fields['items'] = is_string($fields['items'])
            ? json_decode($fields['items'], true)
            : $fields['items'];
    }

    // Procesar nuevos documentos
    if (isset($uploadedFiles['documents'])) {
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $documents = [];
        foreach ($uploadedFiles['documents'] as $uploadedFile) {
            if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
                $filename = $uploadedFile->getClientFilename();
                $extension = pathinfo($filename, PATHINFO_EXTENSION);

                if (strtolower($extension) !== 'pdf') {
                    continue;
                }

                $basename = bin2hex(random_bytes(8));
                $newFilename = sprintf('%s.%0.8s', $basename, $extension);

                try {
                    $uploadedFile->moveTo($uploadDir . $newFilename);
                    $documents[] = [
                        'name' => $filename,
                        'file_content' => 'uploads/' . $newFilename,
                        'document_type' => 'shipment_doc'
                    ];
                } catch (\Exception $e) {
                    error_log("Error al guardar archivo: " . $e->getMessage());
                    continue;
                }
            }
        }
        $fields['new_documents'] = $documents;
    }

    $fields['id'] = $args['id'];
    $shipments = new Shipments($this->get("db"));
    $resp = $shipments->updateShipment((object)$fields)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// PATCH - Actualizar estado del envío
$app->patch("/shipment/{id:[0-9]+}/status", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();

    $verificar = [
        "status" => [
            "type" => "string",
            "values" => ["pendiente", "en_transito", "entregado", "cancelado"]
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    if ($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $shipments = new Shipments($this->get("db"));
        $resp = $shipments->updateShipmentStatus($args["id"], $fields["status"])->getResult();
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Subir documento al envío
$app->post("/shipment/{id:[0-9]+}/document", function (Request $request, Response $response, array $args) {
    $uploadedFiles = $request->getUploadedFiles();

    if (!isset($uploadedFiles['documents']) || empty($uploadedFiles['documents'])) {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = "No se encontraron documentos para subir";
        $resp->data = null;
        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(400);
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $documents = [];
    foreach ($uploadedFiles['documents'] as $uploadedFile) {
        if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
            $filename = $uploadedFile->getClientFilename();
            $extension = pathinfo($filename, PATHINFO_EXTENSION);

            if (strtolower($extension) !== 'pdf') {
                continue;
            }

            $basename = bin2hex(random_bytes(8));
            $newFilename = sprintf('%s.%0.8s', $basename, $extension);

            try {
                $uploadedFile->moveTo($uploadDir . $newFilename);
                $document = new \stdClass();
                $document->name = $filename;
                $document->file_content = 'uploads/' . $newFilename;
                $document->document_type = 'shipment_doc';

                $shipments = new Shipments($this->get("db"));
                $result = $shipments->addDocument($args["id"], $document)->getResult();

                if ($result->ok) {
                    $documents[] = $result->data;
                }
            } catch (\Exception $e) {
                error_log("Error al guardar archivo: " . $e->getMessage());
                continue;
            }
        }
    }

    $resp = new \stdClass();
    $resp->ok = !empty($documents);
    $resp->msg = $resp->ok ? "Documentos subidos correctamente" : "No se pudo subir ningún documento";
    $resp->data = $documents;

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener documentos de un envío
$app->get("/shipment/{id:[0-9]+}/documents", function (Request $request, Response $response, array $args) {
    $shipments = new Shipments($this->get("db"));
    $resp = $shipments->getShipmentDocuments($args["id"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener documento específico
$app->get("/shipment/document/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $shipments = new Shipments($this->get("db"));
    $resp = $shipments->getDocument($args["id"])->getResult();

    if (!$resp->ok || !$resp->data) {
        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(409);
    }

    $pdfPath = __DIR__ . '/../' . $resp->data->file_content;
    if (!file_exists($pdfPath)) {
        $error = new \stdClass();
        $error->ok = false;
        $error->msg = "El archivo no existe en el servidor";
        $error->data = null;
        $response->getBody()->write(json_encode($error));
        return $response->withStatus(404);
    }

    $fileContent = file_get_contents($pdfPath);
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $fileContent);
    rewind($stream);

    return $response
        ->withHeader("Content-Type", "application/pdf")
        ->withHeader("Content-Disposition", 'inline; filename="' . $resp->data->name . '"')
        ->withBody(new \Slim\Psr7\Stream($stream))
        ->withStatus(200);
});

// DELETE - Eliminar documento
$app->delete("/shipment/document/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $shipments = new Shipments($this->get("db"));
    // Primero obtenemos la información del documento para eliminar el archivo físico
    $docInfo = $shipments->getDocument($args["id"])->getResult();

    if ($docInfo->ok && $docInfo->data) {
        $filePath = __DIR__ . '/../' . $docInfo->data->file_content;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    $resp = $shipments->deleteDocument($args["id"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// DELETE - Eliminar envío
$app->delete("/shipment/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $shipments = new Shipments($this->get("db"));

    // Primero obtenemos los documentos del envío para eliminar los archivos físicos
    $docs = $shipments->getShipmentDocuments($args["id"])->getResult();
    if ($docs->ok && $docs->data) {
        foreach ($docs->data as $doc) {
            $filePath = __DIR__ . '/../' . $doc->file_content;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    // Luego eliminamos el envío de la base de datos
    $resp = $shipments->deleteShipment($args["id"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Subir documento POD
$app->post("/shipment/{id:[0-9]+}/pod", function (Request $request, Response $response, array $args) {
    $files = $request->getUploadedFiles();

    if (!isset($files['pod']) || $files['pod']->getError() !== UPLOAD_ERR_OK) {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = "Error al subir el archivo (asegúrate de usar 'pod' como campo)";
        $resp->data = null;

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(400);
    }

    $file = $files['pod'];

    if ($file->getClientMediaType() !== 'application/pdf') {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = "El archivo debe ser PDF";
        $resp->data = null;

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(400);
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $originalFilename = $file->getClientFilename();
    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
    $basename = bin2hex(random_bytes(8));
    $newFilename = sprintf('%s.%0.8s', $basename, $extension);

    try {
        $file->moveTo($uploadDir . $newFilename);

        $document = new \stdClass();
        $document->name = $originalFilename;
        $document->file_content = 'uploads/' . $newFilename;
        $document->document_type = 'pod';

        $shipments = new Shipments($this->get("db"));
        $resp = $shipments->uploadPOD($args["id"], $document)->getResult();

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus($resp->ok ? 200 : 409);
    } catch (\Exception $e) {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = "Error al procesar el archivo: " . $e->getMessage();
        $resp->data = null;

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(500);
    }
});
