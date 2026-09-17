<?php

require_once __DIR__ . '/../src/config/db.php';
date_default_timezone_set('America/Sao_Paulo');

$incidentes = [];

try {
    if ($conn) {
        $sql = "SELECT 
                    sistema_afetado, 
                    descricao_problema, 
                    criticidade, 
                    TO_CHAR(data_modificacao, 'YYYY-MM-DD HH24:MI:SS') as DATA_ISO,
                    TO_CHAR(data_informativo, 'YYYY-MM-DD HH24:MI:SS') as DATA_INFORMATIVO,
                    TO_CHAR(data_fim_informativo, 'YYYY-MM-DD HH24:MI:SS') as DATA_FIM_INFORMATIVO
                FROM incidentes 
                WHERE ativo = 1 AND excluido = 0
                ORDER BY 
                    CASE criticidade 
                        WHEN 'Critico' THEN 1 
                        WHEN 'Aviso' THEN 2 
                        WHEN 'Informativo' THEN 3
                        ELSE 4 
                    END ASC,
                    data_modificacao DESC,
                    id_incidente DESC";
        
        $stmt = oci_parse($conn, $sql);
        
        if (!$stmt) {
            $e = oci_error($conn);
            throw new Exception("Erro no parse: " . $e['message']);
        }

        $result = oci_execute($stmt);
        
        if (!$result) {
            $e = oci_error($stmt);
            throw new Exception("Erro na execução: " . $e['message']);
        }

        oci_fetch_all($stmt, $incidentes, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
        oci_free_statement($stmt);
    }

} catch (Exception $e) {
    error_log("Erro Banner OCI8: " . $e->getMessage());
}

if (empty($incidentes)) {
    return;
}

$piorCriticidade = 'Informativo'; 
foreach ($incidentes as $inc) {
    $c = ucfirst(strtolower($inc['CRITICIDADE']));
    if ($c === 'Critico') {
        $piorCriticidade = 'Critico';
        break; 
    }
    if ($c === 'Aviso' && $piorCriticidade !== 'Critico') {
        $piorCriticidade = 'Aviso';
    }
}
?>

<style>
    <?php include __DIR__ . '/../assets/banner.css'; ?>
</style>

<header id="main-header" class="header-<?php echo $piorCriticidade; ?>">
    <div class="header-content-wrapper">
        <h1 class="header-title">ALERTAS TI SPM</h1>
        <small style="opacity: 0.8">
            Atualizado às: <?php echo date('H:i'); ?>
        </small>
    </div>

    <div id="alert-list-wrapper">
        <ul class="alert-summary-list" id="alert-list-container">
            <?php foreach ($incidentes as $inc): 
                $tipo = ucfirst(strtolower($inc['CRITICIDADE']));
            ?>
                <li class="alert-summary-item bg-<?php echo $tipo; ?>">
                    <div class="item-title">
                        <?php echo htmlspecialchars($inc['SISTEMA_AFETADO'], ENT_QUOTES, 'UTF-8'); ?>
                          
                    </div>
                    
                    <div class="item-desc">
                        <?php 
                            $texto_seguro = htmlspecialchars($inc['DESCRICAO_PROBLEMA'], ENT_QUOTES, 'UTF-8');
                            $texto_seguro = preg_replace_callback(
                                '/\[([^\]]+)\]\((https?:\/\/(?:(?!&quot;|&lt;|&gt;|&#039;)[^\s])+)\)|(https?:\/\/(?:(?!&quot;|&lt;|&gt;|&#039;)[^\s])+)/i',
                                function($m) {
                                    if (!empty($m[1]) && !empty($m[2])) {
                                        return '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline; font-weight: bold;">' . $m[1] . '</a>';
                                    }
                                    $url = $m[3] ?? $m[0];
                                    $trailing = '';
                                    if (preg_match('/([.,;!?]+)$/', $url, $punct)) {
                                        $trailing = $punct[1];
                                        $url = substr($url, 0, -strlen($trailing));
                                    }
                                    return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline; font-weight: bold;">' . $url . '</a>' . $trailing;
                                },
                                $texto_seguro
                            );
                            echo nl2br($texto_seguro);
                        ?>
                    </div>
                    <div><?php 
                            if ($tipo === 'Informativo' && !empty($inc['DATA_INFORMATIVO'])) {
                                $ts_inicio = strtotime($inc['DATA_INFORMATIVO']);
                                $ts_fim    = !empty($inc['DATA_FIM_INFORMATIVO']) ? strtotime($inc['DATA_FIM_INFORMATIVO']) : null;
                                
                                echo '<div class="info-agendado" style="margin-top: 10px;">';
                                
                                if ($ts_fim) {
                                    echo '<strong>Período:</strong> ' . date('d/m/Y H:i', $ts_inicio);
                                    echo  '  até  ';
                                    
                                    if (date('d/m/Y', $ts_inicio) === date('d/m/Y', $ts_fim)) {
                                        echo date('H:i', $ts_fim); 
                                    } else {
                                        echo date('d/m/Y H:i', $ts_fim);
                                    }
                                } else {
                                    echo '<strong>Início:</strong> ' . date('d/m/Y H:i', $ts_inicio);
                                }
                                
                                echo '</div>';
                            }
                        ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

</header>

<script>
    document.body.style.paddingTop = '0px';
</script>