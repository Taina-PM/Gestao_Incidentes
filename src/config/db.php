
<?php

$db_host    = ''; // Insira o host
$db_port    = ''; // Insira a porta
$db_service = ''; // Insira o service name
$db_user    = ''; // Insira o usuario
$db_pass    = ''; // Insira a senha

$easy_connect = "{$db_host}:{$db_port}/{$db_service}";

$conn = @oci_connect($db_user, $db_pass, $easy_connect, 'AL32UTF8');

if (!$conn) {
    $e = oci_error();
    $msg_erro = isset($e['message']) ? $e['message'] : 'Erro desconhecido no OCI8';
    
    error_log("[DB ERROR] " . $msg_erro);
}