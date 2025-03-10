<?php

namespace utils;

use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpMethodNotAllowedException;

class ErrorHandler
{
    public function __invoke(
        \Throwable $exception,
        $request,
        ResponseInterface $response,
        $displayErrorDetails
    ) {
        $status = 500;
        $error = [
            'ok' => false,
            'msg' => 'Error interno del servidor',
            'data' => null
        ];

        if ($exception instanceof HttpNotFoundException) {
            $status = 404;
            $error['msg'] = 'Ruta no encontrada';
        } elseif ($exception instanceof HttpMethodNotAllowedException) {
            $status = 405;
            $error['msg'] = 'Método no permitido';
        } elseif ($exception instanceof \PDOException) {
            $error['msg'] = 'Error en la base de datos';
            // Log el error real para debugging
            error_log($exception->getMessage());
        }

        if ($displayErrorDetails) {
            $error['error_details'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        $response->getBody()->write(json_encode($error));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
