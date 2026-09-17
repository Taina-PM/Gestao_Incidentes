<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

ini_set('session.gc_maxlifetime', 3600);
session_set_cookie_params([
    'lifetime' => 3600,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']), 
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

if (isset($_SESSION['id_usuario'])) {
    header("Location: admin.php");
    exit;
}

define('ACESSO_INTERNO', true);
require_once 'login.php';