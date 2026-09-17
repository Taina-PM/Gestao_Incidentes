<?php
set_time_limit(60); 

ob_start();
session_start();
date_default_timezone_set('America/Sao_Paulo');

require_once 'src/config/db.php';

$usuario_logado_id = $_SESSION['nome_usuario'] ?? null;

if (!$usuario_logado_id || !isset($_SESSION['perfil'])) {
    header("Location: login.php");
    exit;
}

$perfilUsuario = $_SESSION['perfil'];

$podeCadastrar = ($perfilUsuario === 'ADMIN' || $perfilUsuario === 'OPERADOR');
$podeEditar    = ($perfilUsuario === 'ADMIN' || $perfilUsuario === 'OPERADOR');
$podeExcluir   = ($perfilUsuario === 'ADMIN');

$atributoDesabilitado = (!$podeEditar) ? 'disabled' : ''; 

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $acao = $_POST['acao'];

    if (in_array($acao, ['toggle', 'get_data'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');

        try {
            if ($acao === 'toggle') {
                if (!$podeEditar) throw new Exception("Acesso Negado: Seu perfil ({$perfilUsuario}) não permite alterar o status.");

                $id_incidente = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                $ativo_post   = $_POST['ativo'];
                $novo_status  = ($ativo_post === 'true' || $ativo_post === '1' || $ativo_post == 1) ? 1 : 0;

                if (!$id_incidente) throw new Exception("ID inválido.");

                $sql = "UPDATE incidentes SET ativo = :ativo, usuario_modificacao = :usuario, data_modificacao = SYSDATE WHERE id_incidente = :id";
                $stmt = oci_parse($conn, $sql);
                
                oci_bind_by_name($stmt, ':ativo', $novo_status);
                oci_bind_by_name($stmt, ':usuario', $usuario_logado_id);
                oci_bind_by_name($stmt, ':id', $id_incidente);
                
                if (!oci_execute($stmt)) {
                    $e = oci_error($stmt);
                    throw new Exception($e['message']);
                }
                oci_free_statement($stmt);

                echo json_encode([
                    'success' => true, 
                    'nova_data' => date('d/m/Y') . '<br>' . date('H:i'), 
                    'status_aplicado' => (int)$novo_status
                ]);

            } elseif ($acao === 'get_data') {
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

                $sql = "SELECT i.*, 
                        TO_CHAR(data_informativo, 'YYYY-MM-DD') as DATA_DIA, 
                        TO_CHAR(data_informativo, 'HH24:MI') as DATA_HORA,
                        TO_CHAR(data_fim_informativo, 'YYYY-MM-DD') as DATA_DIA_FIM, 
                        TO_CHAR(data_fim_informativo, 'HH24:MI') as DATA_HORA_FIM 
                        FROM incidentes i WHERE id_incidente = :id";    

                $stmt = oci_parse($conn, $sql);
                oci_bind_by_name($stmt, ':id', $id);
                oci_execute($stmt);
                
                $dados = oci_fetch_array($stmt, OCI_ASSOC);
                if (!$dados) throw new Exception("Não encontrado");

                echo json_encode(['success' => true, 'data' => array_change_key_case($dados, CASE_LOWER)]);
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    switch ($acao) {
        case 'excluir':
            try {
                if (!$podeExcluir) throw new Exception("Acesso Negado: Apenas Administradores podem excluir incidentes.");

                $idExcluir = filter_input(INPUT_POST, 'id_incidente', FILTER_VALIDATE_INT);
                if (!$idExcluir) throw new Exception("ID de incidente inválido.");

                $sqlDelete = "UPDATE incidentes SET excluido = 1, ativo = 0, usuario_modificacao = :usuario, data_modificacao = SYSDATE 
                              WHERE id_incidente = :id AND excluido = 0";
                
                $stmtDel = oci_parse($conn, $sqlDelete);
                oci_bind_by_name($stmtDel, ':id', $idExcluir);
                oci_bind_by_name($stmtDel, ':usuario', $usuario_logado_id);

                if (!oci_execute($stmtDel, OCI_COMMIT_ON_SUCCESS)) {
                    throw new Exception("Erro Oracle: " . oci_error($stmtDel)['message']);
                }

                if (oci_num_rows($stmtDel) === 0) {
                    throw new Exception("O incidente não foi encontrado ou já foi excluído.");
                }

                oci_free_statement($stmtDel);
                if (ob_get_length()) { ob_end_clean(); }
                
                header("Location: admin.php?msg=excluido");
                exit;
            } catch (Exception $e) {
                error_log("[ERRO EXCLUSÃO] " . $e->getMessage());
                header("Location: admin.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
                exit;
            }
            break;

        case 'criar':
            if (!$podeCadastrar) {
                header("Location: admin.php?msg=erro&detalhe=Acesso+Negado:+Perfil+sem+permissao+para+cadastrar.");
                exit;
            }
            
        case 'editar':
            try {
                if ($acao === 'editar' && !$podeEditar) {
                    throw new Exception("Acesso Negado: Perfil sem permissão para editar.");
                }

                $sistema     = substr(trim(strip_tags($_POST['sistema'])), 0, 100);
                $criticidade = strip_tags($_POST['criticidade']); 
                $descricao   = substr(trim(strip_tags($_POST['descricao'])), 0, 2000); 
                $causa       = substr(trim(strip_tags($_POST['causa'] ?? '')), 0, 500);
                $impacto     = substr(trim(strip_tags($_POST['impacto'] ?? '')), 0, 500);

                if (empty($sistema) || empty($criticidade) || empty($descricao)) {
                    throw new Exception("Preencha todos os campos obrigatórios (*).");
                }

                $diaIniInput = $acao === 'criar' ? 'data_info_dia_ini' : 'data_info_dia';
                $horaIniInput = $acao === 'criar' ? 'hora_info_ini' : 'data_info_hora';
                $diaFimInput = $acao === 'criar' ? 'data_info_dia_fim' : 'data_info_dia_fim';
                $horaFimInput = $acao === 'criar' ? 'hora_info_fim' : 'data_info_hora_fim';

                $dataInfoBind = null;
                $dataFimBind  = null;

                if ($criticidade === 'Informativo') {
                    if (!empty($_POST[$diaIniInput]) && !empty($_POST[$horaIniInput])) {
                        $dataInfoBind = $_POST[$diaIniInput] . ' ' . $_POST[$horaIniInput];
                    }
                    if (!empty($_POST[$diaFimInput]) && !empty($_POST[$horaFimInput])) {
                        $dataFimBind = $_POST[$diaFimInput] . ' ' . $_POST[$horaFimInput];
                    }
                }

                if ($acao === 'criar') {
                    $sql = "INSERT INTO incidentes 
                            (sistema_afetado, descricao_problema, causa_tecnica, impacto_operacional, criticidade, usuario_modificacao, ativo, data_modificacao, excluido, data_informativo, data_fim_informativo) 
                            VALUES 
                            (:sistema, :descricao, :causa, :impacto, :criticidade, :usuario, 0, SYSDATE, 0, TO_DATE(:datainfo, 'YYYY-MM-DD HH24:MI'), TO_DATE(:datafim, 'YYYY-MM-DD HH24:MI'))";
                } else {
                    $idEditar = filter_input(INPUT_POST, 'id_incidente', FILTER_VALIDATE_INT);
                    if (!$idEditar) throw new Exception("Tentativa de manipulação de ID inválido.");

                    $sql = "UPDATE incidentes SET 
                            sistema_afetado = :sistema, descricao_problema = :descricao, causa_tecnica = :causa, impacto_operacional = :impacto,
                            criticidade = :criticidade, usuario_modificacao = :usuario, data_modificacao = SYSDATE,
                            data_informativo = TO_DATE(:datainfo, 'YYYY-MM-DD HH24:MI'), data_fim_informativo = TO_DATE(:datafim, 'YYYY-MM-DD HH24:MI')
                            WHERE id_incidente = :id";
                }

                $stmt = oci_parse($conn, $sql);
                
                oci_bind_by_name($stmt, ':sistema', $sistema);
                oci_bind_by_name($stmt, ':descricao', $descricao);
                oci_bind_by_name($stmt, ':causa', $causa);
                oci_bind_by_name($stmt, ':impacto', $impacto);
                oci_bind_by_name($stmt, ':criticidade', $criticidade);
                oci_bind_by_name($stmt, ':usuario', $usuario_logado_id);
                oci_bind_by_name($stmt, ':datainfo', $dataInfoBind);
                oci_bind_by_name($stmt, ':datafim', $dataFimBind);
                
                if ($acao === 'editar') oci_bind_by_name($stmt, ':id', $idEditar);

                if (!oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
                    throw new Exception("Erro Oracle ao persistir dados: " . oci_error($stmt)['message']);
                }
                
                oci_free_statement($stmt);
                header("Location: admin.php?msg=" . ($acao === 'criar' ? "criado" : "editado"));
                exit;

            } catch (Exception $e) {
                error_log("[ERRO " . strtoupper($acao) . "] " . $e->getMessage());
                
                if ($acao === 'criar' && strpos($e->getMessage(), 'ORA-02290') !== false) {
                    $feedback_msg = "Erro: Dados inválidos foram enviados.";
                } else {
                    $feedback_msg = "Não foi possível salvar o incidente agora. Tente novamente. Detalhe: " . $e->getMessage();
                }
            }
            break;
    }
}


$incidentes = [];
$paginaAtual = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($paginaAtual < 1) $paginaAtual = 1;

$filtroNivel = filter_input(INPUT_GET, 'nivel', FILTER_DEFAULT) ?: '';
if (!isset($feedback_msg)) $feedback_msg = '';

try {
    $itensPorPagina = 10;
    $condicaoWhere = "WHERE i.excluido = 0";
    
    if (!empty($filtroNivel)) {
        $condicaoWhere .= " AND i.criticidade = :nivel";
    }

    $sql_count = "SELECT COUNT(1) AS TOTAL FROM incidentes i $condicaoWhere";
    $stmt_count = oci_parse($conn, $sql_count);
    
    if (!empty($filtroNivel)) oci_bind_by_name($stmt_count, ':nivel', $filtroNivel);
    
    oci_execute($stmt_count);
    $row_count = oci_fetch_array($stmt_count, OCI_ASSOC);
    $totalRegistros = (int)$row_count['TOTAL'];
    
    $totalPaginas = ($totalRegistros > 0) ? ceil($totalRegistros / $itensPorPagina) : 1;
    if ($paginaAtual > $totalPaginas) $paginaAtual = $totalPaginas;

    $offset = ($paginaAtual - 1) * $itensPorPagina;

    $sql_lista = "SELECT i.*, 
                i.usuario_modificacao AS nome_usuario, 
                TO_CHAR(i.data_modificacao, 'YYYY-MM-DD HH24:MI:SS') AS DATA_RAW,
                TO_CHAR(i.data_informativo, 'YYYY-MM-DD HH24:MI:SS') AS DATA_INFORMATIVO,
                TO_CHAR(i.data_fim_informativo, 'YYYY-MM-DD HH24:MI:SS') AS DATA_FIM_INFORMATIVO
                FROM incidentes i
                $condicaoWhere
                ORDER BY 
                    CASE i.criticidade WHEN 'Critico' THEN 1 WHEN 'Aviso' THEN 2 WHEN 'Informativo' THEN 3 ELSE 4 END ASC, 
                    i.ATIVO DESC, 
                    i.data_modificacao DESC
                OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";

    $stmt = oci_parse($conn, $sql_lista);
    oci_bind_by_name($stmt, ':offset', $offset);
    oci_bind_by_name($stmt, ':limit', $itensPorPagina);
    if (!empty($filtroNivel)) oci_bind_by_name($stmt, ':nivel', $filtroNivel);
    
    oci_execute($stmt);
    
    while ($row = oci_fetch_array($stmt, OCI_ASSOC)) {
        $incidentes[] = $row;
    }
    oci_free_statement($stmt);

} catch (Exception $e) {
    error_log($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Incidentes | Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <div id="toast"></div>

    <header>
        <div class="header-content">
            <div class="logo">
                <h1>Gestão de Incidentes</h1>
                <small>Painel Administrativo TI</small>
            </div>
            <div class="user-menu">
                <span><?= htmlspecialchars($_SESSION['nome_usuario'] ?? 'Admin') ?> (<?= htmlspecialchars($_SESSION['perfil']) ?>)</span>
                <a href="logout.php" class="btn-logout" style="margin-left: 15px; color: #d32f2f; text-decoration: none; font-weight: bold;">Sair</a>
            </div>
        </div>
    </header>

    <div class="container">
        
        <?php if($feedback_msg): ?>
            <div style="background: #ffebee; color: #c62828; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                 <?= htmlspecialchars($feedback_msg) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>
                Cadastrar Novo Incidente 
                <?php if(!$podeCadastrar): ?>
                    <span style="font-size: 12px; color: #d32f2f; font-weight: normal; margin-left: 10px;">(Modo Somente Leitura)</span>
                <?php endif; ?>
            </h2>
            
            <form method="POST" onsubmit="return validarDatasCadastro()">
                <input type="hidden" name="acao" value="criar">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Sistema Afetado <span style="color:red">*</span></label>
                        <input type="text" name="sistema" required placeholder="Ex: SAP, VPN, Rede Wi-Fi..." maxlength="100" <?= $atributoDesabilitado ?>>
                    </div>
                    
                    <div class="form-group">
                        <label>Criticidade</label>
                        <select name="criticidade" id="new_criticidade" onchange="toggleDataInfo('new_criticidade', 'new_data_container')" required <?= $atributoDesabilitado ?>>
                            <option value="Informativo">Informativo</option>
                            <option value="Aviso">Aviso</option>
                            <option value="Critico">Crítica</option>
                        </select>
                    </div>

                    <div class="form-group form-full">
                        <label>Descrição do Problema (Visível no Portal) <span style="color:red">*</span></label>
                        <textarea name="descricao" rows="3" required <?= $atributoDesabilitado ?>></textarea>
                    </div>

                    <div class="form-group">
                        <label>Causa Técnica (Interno TI)</label>
                        <input type="text" name="causa" placeholder="Ex: Falha no disco do servidor DB01" <?= $atributoDesabilitado ?>>
                    </div>

                    <div class="form-group">
                        <label>Impacto Operacional</label>
                        <input type="text" name="impacto" placeholder="Ex: Lentidão no faturamento" <?= $atributoDesabilitado ?>>
                    </div>

                    <div class="form-group form-full" id="new_data_container" style="padding: 10px; border-radius: 5px; background: #f4f7f6;">
                        <label style="font-weight: bold; display: block; margin-bottom: 8px;">Agendamento (Informativo)</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div style="display: flex; gap: 10px; border-right: 1px solid #ddd; padding-right: 15px;">
                                <div style="flex: 2;">
                                    <label style="font-size: 0.8rem; color: #555;">Data Início:</label>
                                    <input type="date" name="data_info_dia_ini" id="new_data_ini" style="width: 100%;" <?= $atributoDesabilitado ?>>
                                </div>
                                <div style="flex: 1;">
                                    <label style="font-size: 0.8rem; color: #555;">Hora:</label>
                                    <input type="time" name="hora_info_ini" id="new_hora_ini" style="width: 100%;" <?= $atributoDesabilitado ?>>
                                </div>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <div style="flex: 2;">
                                    <label style="font-size: 0.8rem; color: #555;">Data Fim:</label>
                                    <input type="date" name="data_info_dia_fim" id="new_data_fim" style="width: 100%;" <?= $atributoDesabilitado ?>>
                                </div>
                                <div style="flex: 1;">
                                    <label style="font-size: 0.8rem; color: #555;">Hora:</label>
                                    <input type="time" name="hora_info_fim" id="new_hora_fim" style="width: 100%;" <?= $atributoDesabilitado ?>>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 20px; overflow: hidden;">
                    <button type="submit" class="btn-submit" <?= $atributoDesabilitado ?> <?= !$podeCadastrar ? 'style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>
                        Salvar Incidente
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header-actions">
                <h2>Painel de Controle</h2>
                
                <div class="filter-bar">
                    <select id="filterLevel" onchange="aplicarFiltroServer()">
                        <option value="">Todos os Níveis</option>
                        <option value="Critico" <?= (isset($_GET['nivel']) && $_GET['nivel'] == 'Critico') ? 'selected' : '' ?>>Crítico</option>
                        <option value="Aviso" <?= (isset($_GET['nivel']) && $_GET['nivel'] == 'Aviso') ? 'selected' : '' ?>>Aviso</option>
                        <option value="Informativo" <?= (isset($_GET['nivel']) && $_GET['nivel'] == 'Informativo') ? 'selected' : '' ?>>Informativo</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table id="tabelaIncidentes">
                    <thead>
                        <tr>
                            <th style="width: 80px; text-align: center;">Status</th>
                            <th style="width: 20%;">Sistema</th>
                            <th>Detalhes</th>
                            <th style="width: 100px;">Nível</th>
                            <th style="width: 150px;">Última Atualização</th>
                            <th style="width: 80px; text-align: center;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($incidentes)): ?>
                            <tr><td colspan="6" style="text-align:center; padding: 30px; color:#999;">Nenhum incidente registrado.</td></tr>
                        <?php else: ?>
                            <?php 
                            foreach($incidentes as $inc): 
                                $inc = array_change_key_case($inc, CASE_UPPER);
                                $sistema = $inc['SISTEMA_AFETADO'] ?? '';
                                $desc    = $inc['DESCRICAO_PROBLEMA'] ?? '';
                                $causa   = $inc['CAUSA_TECNICA'] ?? '';
                                $impacto = $inc['IMPACTO_OPERACIONAL'] ?? '';
                                $crit    = $inc['CRITICIDADE'] ?? 'Informativo';
                                $user    = $inc['NOME_USUARIO'] ?? 'TI';
                                $ativo   = $inc['ATIVO'];
                                $id      = $inc['ID_INCIDENTE'];
                                
                                $data_raw = $inc['DATA_RAW'] ?? null;
                                $data_formatada_html = '-';
                                if (!empty($data_raw)) {
                                    $ts = strtotime($data_raw);
                                    $data_formatada_html = $ts ? date('d/m/Y', $ts) . '<br>' . date('H:i', $ts) : '-';
                                }
                            ?>
                            <tr class="item-incidente <?= $ativo == 1 ? 'active-row' : '' ?>" data-nivel="<?= $crit ?>">
                                <td style="text-align: center; vertical-align: middle;">
                                    <label class="switch">
                                        <input type="checkbox" onchange="toggleStatus(<?= $id ?>, this)" <?= $ativo == 1 ? 'checked' : '' ?> <?= !$podeEditar ? 'disabled' : '' ?>>
                                        <span class="slider <?= !$podeEditar ? 'disabled-slider' : '' ?>" <?= !$podeEditar ? 'style="cursor: not-allowed; opacity: 0.6;"' : '' ?>></span>
                                    </label>
                                    <div style="font-size: 0.7rem; color: #999; margin-top: 5px;">
                                        <span class="status-text"><?= $ativo == 1 ? 'ATIVO' : 'OFF' ?></span>
                                    </div>
                                </td>

                                <td class="col-sistema">
                                    <strong style="color: #333; font-size: 1.05rem;"><?= htmlspecialchars($sistema) ?></strong>
                                </td>

                                <td class="col-detalhes">
                                    <div style="margin-bottom: 5px; color: #444; max-height: 100px; overflow-y: auto;">
                                        <?php 
                                            $desc_trunc = (strlen($desc) > 500) ? substr($desc, 0, 500) . "..." : $desc;
                                            $desc_html = htmlspecialchars($desc_trunc, ENT_QUOTES, 'UTF-8');
                                            $desc_html = preg_replace_callback(
                                                '/\[([^\]]+)\]\((https?:\/\/(?:(?!&quot;|&lt;|&gt;|&#039;)[^\s])+)\)|(https?:\/\/(?:(?!&quot;|&lt;|&gt;|&#039;)[^\s])+)/i',
                                                function($m) {
                                                    if (!empty($m[1]) && !empty($m[2])) {
                                                        return '<a href="' . $m[2] . '" target="_blank" style="color: var(--primary); text-decoration: underline; font-weight: 600;">' . $m[1] . '</a>';
                                                    }
                                                    $url = $m[3] ?? $m[0];
                                                    $trailing = '';
                                                    if (preg_match('/([.,;!?]+)$/', $url, $punct)) {
                                                        $trailing = $punct[1];
                                                        $url = substr($url, 0, -strlen($trailing));
                                                    }
                                                    return '<a href="' . $url . '" target="_blank" style="color: var(--primary); text-decoration: underline; font-weight: 600;">' . $url . '</a>' . $trailing;
                                                },
                                                $desc_html
                                            );
                                            echo nl2br($desc_html);
                                        ?>
                                    </div>                                      
                                    <div style="font-size: 0.85rem; color: #777; background: #f5f5f5; padding: 5px 8px; border-radius: 4px; display: inline-block; margin-right: 5px;">
                                        <strong>Causa:</strong> <?= htmlspecialchars($causa ?: 'Em análise') ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #777; background: #f5f5f5; padding: 5px 8px; border-radius: 4px; display: inline-block; margin-right: 5px;">
                                        <strong>Impacto:</strong> <?= htmlspecialchars($impacto ?: 'Em análise') ?>
                                    </div>

                                    <?php 
                                        $data_ini_db = $inc['DATA_INFORMATIVO'] ?? null;
                                        $data_fim_db = $inc['DATA_FIM_INFORMATIVO'] ?? null;
                                        
                                        if ($crit === 'Informativo' && !empty($data_ini_db)) {
                                            $ts_inicio = strtotime($data_ini_db);
                                            $ts_fim    = !empty($data_fim_db) ? strtotime($data_fim_db) : null;

                                            echo '<div style="font-size: 0.85rem; padding: 5px 8px; border-radius: 4px; display: inline-block; margin-top: 5px; background: #eef2f7; border-left: 3px solid #007bff;">';
                                            echo '<strong>Início:</strong> ' . date('d/m/Y H:i', $ts_inicio);
                                            
                                            if ($ts_fim) {
                                                echo ' | <strong>Fim:</strong> ';
                                                echo date('d/m/Y', $ts_inicio) === date('d/m/Y', $ts_fim) ? date('H:i', $ts_fim) : date('d/m/Y H:i', $ts_fim);
                                            }
                                            echo '</div>';
                                        }
                                    ?>
                                </td>

                                <td>
                                    <span class="badge <?= htmlspecialchars($crit) ?>">
                                        <?= htmlspecialchars($crit) ?>
                                    </span>
                                </td>

                                <td style="font-size: 0.85rem; color: #666;">
                                    <span id="data-mod-<?= $id ?>"><?= $data_formatada_html ?></span>
                                    <br>
                                    <span style="font-size: 0.75rem; color: #999;">por <?= htmlspecialchars($user) ?></span>
                                </td>

                                <td style="text-align: center; vertical-align: middle;">
                                    <button type="button" class="btn-icon-edit" onclick="abrirModalEdicao(<?= $id ?>)" title="<?= $podeEditar ? 'Editar' : 'Visualizar Detalhes' ?>" style="background:none; border:none; cursor:pointer; color:#007bff; margin-right:5px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    
                                    <?php if ($podeExcluir): ?>
                                    <button type="button" class="btn-icon-delete" onclick="abrirModalExclusao(<?= $id ?>)" title="Excluir">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                                            <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
                                        </svg>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

  
  
       <?php if ($totalPaginas > 1): ?>
        <div class="pagination-wrapper">
            <nav class="pagination">
                <?php $paramsFiltro = !empty($filtroNivel) ? "&nivel=" . urlencode($filtroNivel) : ""; ?>

                <?php if ($paginaAtual > 1): ?>
                    <a href="?page=<?= $paginaAtual - 1 ?><?= $paramsFiltro ?>" class="pg-item pg-nav">Anterior</a>
                <?php endif; ?>

                <?php 
                $range = 2; 
                for ($i = 1; $i <= $totalPaginas; $i++): 
                    if ($i == 1 || $i == $totalPaginas || ($i >= $paginaAtual - $range && $i <= $paginaAtual + $range)):
                        $activeClass = ($i == $paginaAtual) ? 'active' : '';
                ?>
                    <a href="?page=<?= $i ?><?= $paramsFiltro ?>" class="pg-item <?= $activeClass ?>"><?= $i ?></a>
                <?php 
                    elseif ($i == $paginaAtual - $range - 1 || $i == $paginaAtual + $range + 1):
                        echo '<span class="pg-dots">...</span>';
                    endif;
                endfor; 
                ?>

                <?php if ($paginaAtual < $totalPaginas): ?>
                    <a href="?page=<?= $paginaAtual + 1 ?><?= $paramsFiltro ?>" class="pg-item pg-nav">Próximo</a>
                <?php endif; ?>
            </nav>
        </div>

        <?php endif; ?>

    <?php if ($podeExcluir): ?>
    <div id="modalDelete" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-title" style="font-size: 1.2rem; font-weight: bold; margin-bottom: 10px;">Remover Incidente</div>
            <div class="modal-desc">
                Tem certeza que deseja remover este incidente da lista? <br>
                <small style="color:#666;">Ele deixará de aparecer no painel e no banner</small>
            </div>
            <form method="POST">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id_incidente" id="id_exclusao_input">
                
                <div class="modal-actions">
                    <button type="button" class="btn-modal btn-cancel" onclick="fecharModalExclusao()">Cancelar</button>
                    <button type="submit" class="btn-modal btn-confirm-delete">Sim, Remover</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div id="modalEdit" class="modal-overlay">
        <div class="modal-card-lg">
            <div class="modal-header" style="display:flex; justify-content:space-between; margin-bottom: 15px;">
                <h3 style="margin:0;"><?= $podeEditar ? 'Editar Incidente' : 'Detalhes do Incidente' ?></h3>
                <button type="button" onclick="fecharModalEdicao()" style="background:none; border:none; cursor:pointer; font-size:1.2rem;">&times;</button>
            </div>

            <form method="POST" id="formEdicao" onsubmit="return validarDatasEdicao()">
                <input type="hidden" name="acao" value="editar">
                <input type="hidden" name="id_incidente" id="edit_id">

                <div class="modal-body">
                    <div id="editLoading" style="text-align: center; padding: 20px;">
                        <p style="color: #666;">Carregando dados...</p>
                    </div>

                    <div id="editFields" style="display: none;">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Sistema Afetado <span style="color:red">*</span></label>
                                <input type="text" name="sistema" id="edit_sistema" required <?= $atributoDesabilitado ?>>
                            </div>
                            
                            <div class="form-group">
                                <label>Criticidade</label>
                                <select name="criticidade" id="edit_criticidade" onchange="toggleDataInfo('edit_criticidade', 'edit_data_container')" required <?= $atributoDesabilitado ?>>
                                    <option value="Informativo">Informativo</option>
                                    <option value="Aviso">Aviso</option>
                                    <option value="Critico">Crítica</option>
                                </select>
                            </div>

                            <div class="form-group form-full">
                                <label>Descrição <span style="color:red">*</span></label>
                                <textarea name="descricao" id="edit_descricao" rows="3" required <?= $atributoDesabilitado ?>></textarea>
                            </div>

                            <div class="form-group">
                                <label>Causa Técnica</label>
                                <input type="text" name="causa" id="edit_causa" <?= $atributoDesabilitado ?>>
                            </div>
                            
                            <div class="form-group">
                                <label>Impacto</label>
                                <input type="text" name="impacto" id="edit_impacto" <?= $atributoDesabilitado ?>>
                            </div>

                            <div class="form-group form-full" id="edit_data_container" style="display:none; padding: 15px; background: #fdfdfd; border: 1px dashed #ddd; border-radius: 8px; margin-top: 15px;">
                                <label style="font-weight: bold; display: block; margin-bottom: 10px; color: #555;">Agendamento Informativo</label>
                                
                                <div style="display: flex; flex-direction: column; gap: 10px;">
                                    <div style="display: flex; gap: 15px; align-items: flex-end;">
                                        <div style="flex: 2;">
                                            <label style="font-size: 0.75rem; color: #777; display: block; margin-bottom: 5px;">Data Início:</label>
                                            <input type="date" name="data_info_dia" id="edit_data_dia" style="width: 100%;" <?= $atributoDesabilitado ?>>
                                        </div>
                                        <div style="flex: 2;">
                                            <label style="font-size: 0.75rem; color: #777; display: block; margin-bottom: 5px;">Hora Início:</label>
                                            <input type="time" name="data_info_hora" id="edit_data_hora" style="width: 100%;" <?= $atributoDesabilitado ?>>
                                        </div>
                                        <div style="flex: 1;"></div>
                                    </div>

                                    <div style="display: flex; gap: 15px; align-items: flex-end;">
                                        <div style="flex: 2;">
                                            <label style="font-size: 0.75rem; color: #777; display: block; margin-bottom: 5px;">Data Fim:</label>
                                            <input type="date" name="data_info_dia_fim" id="edit_data_dia_fim" style="width: 100%;" <?= $atributoDesabilitado ?>>
                                        </div>
                                        <div style="flex: 2;">
                                            <label style="font-size: 0.75rem; color: #777; display: block; margin-bottom: 5px;">Hora Fim:</label>
                                            <input type="time" name="data_info_hora_fim" id="edit_data_hora_fim" style="width: 100%;" <?= $atributoDesabilitado ?>>
                                        </div>

                                        <div style="flex: 1; display: flex; flex-direction: column;">
                                            <?php if ($podeEditar): ?>
                                            <button type="button" class="btn-clear-data" onclick="limparDataEdicao()" style="width: 100%;">
                                                Limpar
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-modal btn-cancel" onclick="fecharModalEdicao()"><?= $podeEditar ? 'Cancelar' : 'Fechar' ?></button>
                        
                        <?php if ($podeEditar): ?>
                        <button type="submit" class="btn-submit" id="btnSalvarEdicao" disabled>
                            <span class="btn-text">Salvar Alterações</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

  <script>
        function toggleDataInfo(selectId, containerId) {
            const select = document.getElementById(selectId);
            const container = document.getElementById(containerId);
            if(select && container) {
                container.style.display = (select.value === 'Informativo') ? 'block' : 'none';
            }
        }

        function limparDataEdicao() {
            document.getElementById('edit_data_dia').value = '';
            document.getElementById('edit_data_hora').value = '';
            document.getElementById('edit_data_dia_fim').value = '';
            document.getElementById('edit_data_hora_fim').value = '';
        }

        function showToast(message, isError = false) {
            const toast = document.getElementById("toast");
            if (!toast) return;

            toast.innerText = message;
            toast.style.backgroundColor = isError ? "#c62828" : "#2cc165ff"; 
            toast.classList.add("show");

            setTimeout(() => toast.classList.remove("show"), 3000);
        }

        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            const msg = urlParams.get('msg');
            
            toggleDataInfo('new_criticidade', 'new_data_container');

            if (msg === 'criado') showToast("Novo incidente cadastrado com sucesso!");
            else if (msg === 'excluido') showToast("Incidente removido com sucesso!");
            else if (msg === 'editado') showToast("Incidente atualizado com sucesso!");
            else if (msg === 'erro_permissao') showToast("Você não tem permissão para realizar esta ação.", true);
                    
            if (msg) window.history.replaceState(null, null, window.location.pathname);
        });

        function filtrarTabela() {
            const filterValue = (document.getElementById("filterSearch")?.value || "").toLowerCase();
            const rows = document.querySelectorAll(".item-incidente");
            let encontrados = 0;

            rows.forEach(row => {
                if (row.textContent.toLowerCase().includes(filterValue)) {
                    row.style.display = "";
                    encontrados++;
                } else {
                    row.style.display = "none";
                }
            });

            verificarTabelaVazia(encontrados);
        }

        function verificarTabelaVazia(qtd) {
            const tbody = document.querySelector("#tabelaIncidentes tbody");
            let emptyMsg = document.getElementById("no-results-msg");

            if (qtd === 0 && !emptyMsg) {
                const tr = document.createElement("tr");
                tr.id = "no-results-msg";
                tr.innerHTML = `<td colspan="6" style="text-align:center; padding: 30px; color:#999;">Nenhum incidente corresponde ao filtro.</td>`;
                tbody.appendChild(tr);
            } else if (qtd > 0 && emptyMsg) {
                emptyMsg.remove();
            }
        }

        function aplicarFiltroServer() {
            const nivel = document.getElementById("filterLevel").value;
            window.location.href = `admin.php?page=1&nivel=${nivel}`;
        }

        function toggleStatus(id, checkbox) {
            if (checkbox.disabled) return;

            const isChecked = checkbox.checked;
            const td = (checkbox && typeof checkbox.closest === 'function') ? checkbox.closest('td') : null;
            const row = (checkbox && typeof checkbox.closest === 'function') ? checkbox.closest('tr') : null;
            const statusLabel = td ? td.querySelector('.status-text') : null;
            
            if (statusLabel) statusLabel.innerText = '...';

            const formData = new FormData();
            formData.append('acao', 'toggle');
            formData.append('id', id);
            formData.append('ativo', isChecked ? '1' : '0');
            
            fetch('admin.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(isChecked ? "Incidente ATIVADO" : "Incidente DESATIVADO");
                        if (statusLabel) statusLabel.innerText = isChecked ? 'ATIVO' : 'OFF';
                        if (row) row.classList.toggle('active-row', isChecked);

                        const dateSpan = document.getElementById('data-mod-' + id);
                        if (dateSpan && data.nova_data) dateSpan.innerHTML = data.nova_data;
                    } else {
                        throw new Error(data.error || "Erro desconhecido");
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast("Erro: " + err.message, true);
                    checkbox.checked = !isChecked; 
                    if (statusLabel) statusLabel.innerText = !isChecked ? 'ATIVO' : 'OFF';
                });
        }

        function abrirModalExclusao(id) {
            const inputId = document.getElementById('id_exclusao_input');
            const modal = document.getElementById('modalDelete');
            
            if (inputId && modal) {
                inputId.value = id;
                modal.classList.add('show');
            }
        }

        function fecharModalExclusao() {
            const modal = document.getElementById('modalDelete');
            if (modal) modal.classList.remove('show');
        }

        function abrirModalEdicao(id) {
            const modal = document.getElementById('modalEdit');
            const loading = document.getElementById('editLoading');
            const fields = document.getElementById('editFields');
            const btnSalvar = document.getElementById('btnSalvarEdicao');
            
            modal.classList.add('show');
            modal.style.display = 'flex'; 
            loading.style.display = 'block';
            fields.style.display = 'none';
            if (btnSalvar) btnSalvar.disabled = true;

            const formData = new FormData();
            formData.append('acao', 'get_data');
            formData.append('id', id);

            fetch('admin.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const d = res.data;
                        document.getElementById('edit_id').value = d.id_incidente;
                        document.getElementById('edit_sistema').value = d.sistema_afetado;
                        document.getElementById('edit_criticidade').value = d.criticidade;
                        document.getElementById('edit_descricao').value = d.descricao_problema;
                        document.getElementById('edit_causa').value = d.causa_tecnica || ''; 
                        document.getElementById('edit_impacto').value = d.impacto_operacional || '';
                        
                        document.getElementById('edit_data_dia').value = d.data_dia || '';  
                        document.getElementById('edit_data_hora').value = d.data_hora || ''; 
                        document.getElementById('edit_data_dia_fim').value = d.data_dia_fim || '';  
                        document.getElementById('edit_data_hora_fim').value = d.data_hora_fim || '';

                        toggleDataInfo('edit_criticidade', 'edit_data_container');

                        loading.style.display = 'none';
                        fields.style.display = 'block';
                        // Habilita o botão apenas se ele existir no DOM
                        if (btnSalvar) btnSalvar.disabled = false;
                    } else {
                        alert("Erro ao carregar dados: " + (res.error || "Erro desconhecido"));
                        fecharModalEdicao();
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("Erro de conexão ao buscar dados.");
                    fecharModalEdicao();
                });
        }

        function fecharModalEdicao() {
            const modal = document.getElementById('modalEdit');
            modal.classList.remove('show');
            modal.style.display = 'none';
        }

        function iniciarSalvamento() {
            const btn = document.getElementById('btnSalvarEdicao');
            if (btn) {
                const texto = btn.querySelector('.btn-text');
                if (texto) texto.innerHTML = 'Salvando...';
                btn.disabled = true;
            }
        }

        function validarDatasEdicao() {
            try {
                if (document.getElementById('edit_criticidade').value === 'Informativo') {
                    const dIni = document.getElementById('edit_data_dia').value;
                    const hIni = document.getElementById('edit_data_hora').value || "00:00";
                    const dFim = document.getElementById('edit_data_dia_fim').value;
                    const hFim = document.getElementById('edit_data_hora_fim').value || "00:00";

                    if (dIni && dFim && new Date(`${dFim}T${hFim}`) < new Date(`${dIni}T${hIni}`)) {
                        showToast("Erro: A data de término não pode ser anterior ao início!", true);
                        return false; 
                    }
                }
                iniciarSalvamento(); 
                return true; 
            } catch (e) {
                console.error("Erro na validação:", e);
                iniciarSalvamento();
                return true; 
            }
        }

        function validarDatasCadastro() {
            try {
                const crit = document.getElementById('new_criticidade');
                if (crit && crit.value === 'Informativo') {
                    const dIni = document.getElementById('new_data_ini').value;
                    const hIni = document.getElementById('new_hora_ini').value || "00:00";
                    const dFim = document.getElementById('new_data_fim').value;
                    const hFim = document.getElementById('new_hora_fim').value || "00:00";

                    if (dIni && dFim && new Date(`${dFim}T${hFim}`) < new Date(`${dIni}T${hIni}`)) {
                        showToast("Erro: A data de término não pode ser anterior ao início!", true);
                        return false; 
                    }
                }
                return true; 
            } catch (e) {
                console.error("Erro na validação de cadastro:", e);
                return true;
            }
        }

        window.addEventListener('click', function(e) {
            const modalEdit = document.getElementById('modalEdit');
            const modalDelete = document.getElementById('modalDelete');
            
            if (e.target === modalEdit) fecharModalEdicao();
            if (e.target === modalDelete) fecharModalExclusao();
        });
    </script>
</body>
</html>