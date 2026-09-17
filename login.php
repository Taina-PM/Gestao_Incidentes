<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (empty($login) || empty($senha)) {
        $erro = "Preencha todos os campos.";
    } else {
        $adServer = "ldap://supermercadospaguemenos.com.br";
        $baseDn   = "dc=supermercadospaguemenos,dc=com,dc=br";
        
        $ldap = ldap_connect($adServer);

        if ($ldap) {
            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

            $ldapUser = "{$login}@supermercadospaguemenos.com.br";
            $bind = @ldap_bind($ldap, $ldapUser, $senha);

            if ($bind) {
                $filter = "(sAMAccountName={$login})"; 
                $attributes = array("sAMAccountName", "displayname", "memberof");
                
                $search = ldap_search($ldap, $baseDn, $filter, $attributes);
                $entries = ldap_get_entries($ldap, $search);

                if ($entries['count'] > 0) {
                    $nome_completo = $entries[0]['displayname'][0] ?? $login;
                    $grupos_do_usuario = $entries[0]['memberof'] ?? array();
                    
                    if (isset($grupos_do_usuario['count'])) {
                        unset($grupos_do_usuario['count']);
                    }

                    $perfil = null;
                    $peso_perfil = 0;

                    $grupos_governanca = [
                        '_DS_PORTAL-INTERNO_INCIDENTES_ADMIN'    => ['nome' => 'ADMIN',    'peso' => 3],
                        '_DS_PORTAL-INTERNO_INCIDENTES_OPERADOR' => ['nome' => 'OPERADOR', 'peso' => 2],
                        '_DS_PORTAL-INTERNO_INCIDENTES_LEITOR'   => ['nome' => 'LEITOR',   'peso' => 1]
                    ];

                    foreach ($grupos_do_usuario as $grupo_dn) {
                        foreach ($grupos_governanca as $cn_grupo => $dados_perfil) {
                            if (stripos($grupo_dn, "CN=" . $cn_grupo) !== false) {
                                if ($dados_perfil['peso'] > $peso_perfil) {
                                    $peso_perfil = $dados_perfil['peso'];
                                    $perfil = $dados_perfil['nome'];
                                }
                            }
                        }
                    }

                    if ($perfil !== null) {
                        session_regenerate_id(true);
                        $_SESSION['id_usuario']   = $login;
                        $_SESSION['nome_usuario'] = $nome_completo;
                        $_SESSION['perfil']       = $perfil;

                        header("Location: admin.php");
                        exit;
                    } else {
                        $erro = "Acesso negado. Você não pertence a nenhum grupo com permissão para este sistema.";
                    }
                }
            } else {
                $erro = "Usuário ou senha inválidos.";
            }
            ldap_unbind($ldap);
        } else {
            $erro = "Erro interno: Não foi possível alcançar o servidor AD.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Gestão de Incidentes</title>

    <style>
        <?php include 'assets/login.css'; ?>
    </style>
</head>
<body>

    <div class="login-card">
        <div class="logo">
            <h1>Gestão de Incidentes TI</h1>
        </div>

        <?php if ($erro): ?>
            <div class="alert" style="color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
                <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST" autocomplete="off">
            <div class="form-group">
                <label>Login</label>
                <input type="text" name="login" required autofocus placeholder="Digite seu login...">
            </div>

            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="senha" required placeholder="Digite sua senha...">
            </div>

            <button type="submit" class="btn">Entrar</button>
        </form>
    </div>

</body>
</html>