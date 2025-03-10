<?php
namespace utils;

class Prepare {
    public static function cleanTxt($value) {
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }

    public static function UCfirst($texto, $encode = "UTF-8") {
        $resp = str_replace(",", "", $texto);
        $resp = mb_strtolower($resp, $encode);
        $resp = ucwords($resp);
        return $resp;
    }

    public static function getDate($format = "Y-m-d") {
        return date($format);
    }

    public static function getDateTime($format = "Y-m-d H:i:s") {
        return date($format);
    }

    public static function OnlyNumbers($mixed_input) {
        return filter_var($mixed_input, FILTER_SANITIZE_NUMBER_INT);
    }

    public static function randomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $string = '';
        for ($i = 0; $i < $length; $i++) {
            $string .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $string;
    }

    public static function formatMoney($amount, $decimals = 2) {
        return number_format($amount, $decimals, '.', '');
    }

    public static function validateDate($date, $format = 'Y-m-d') {
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    public static function generateUniqueId($prefix = '') {
        return uniqid($prefix, true);
    }

    public static function formatPhone($phone) {
        // Eliminar todo excepto números
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) === 10) {
            return sprintf("(%s) %s-%s",
                substr($phone, 0, 3),
                substr($phone, 3, 3),
                substr($phone, 6)
            );
        }
        
        return $phone;
    }

    public static function slugify($text) {
        // Convertir a minúsculas
        $text = mb_strtolower($text);
        
        // Reemplazar caracteres especiales
        $text = str_replace(
            array('á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'),
            array('a', 'e', 'i', 'o', 'u', 'n', 'u'),
            $text
        );
        
        // Reemplazar cualquier caracter que no sea letra o número con guión
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        
        // Reemplazar múltiples guiones con uno solo
        $text = preg_replace('/-+/', '-', $text);
        
        // Eliminar guiones al inicio y final
        return trim($text, '-');
    }

    public static function isValidJSON($string) {
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    public static function base64Encode($data) {
        return base64_encode($data);
    }

    public static function base64Decode($data) {
        return base64_decode($data);
    }

    public static function isBase64($data) {
        if (!is_string($data)) return false;
        return base64_encode(base64_decode($data, true)) === $data;
    }
}