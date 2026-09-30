<?php
// api/reports.php
require_once 'auth_middleware.php';
$user_id = getCurrentUserId();
$role = getCurrentUserRole();

try {
    $pdo = getConnection();
    $where = "WHERE (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')";
    $params = [];
    
    if ($role !== 'admin') {
        $where .= " AND (t.assigned_to = ? OR t.id IN (SELECT task_id FROM task_shares WHERE user_id = ?))";
        $params = [$user_id, $user_id];
    }
    
    // 1. Total Atendimentos
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) as total FROM tasks t $where");
    $stmtTotal->execute($params);
    $totalAtendimentos = $stmtTotal->fetch()['total'];
    
    // 2. Ranking Atendentes (lista completa + %)
    // Group by assigned_to
    $stmtRankingAtendentes = $pdo->prepare("
        SELECT u.name as atendente, COUNT(t.id) as total 
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        $where
        GROUP BY t.assigned_to
        ORDER BY total DESC
    ");
    $stmtRankingAtendentes->execute($params);
    $rankingAtendentes = $stmtRankingAtendentes->fetchAll();
    $totalAtendentes = array_sum(array_map(fn($r) => (int)$r['total'], $rankingAtendentes));
    foreach ($rankingAtendentes as &$row) {
        $row['percent'] = $totalAtendentes > 0 ? round(((int)$row['total'] / $totalAtendentes) * 100, 1) : 0;
    }
    unset($row);
    
    // 3. Ranking Imóveis (lista completa + %, Top 5 fatiado no retorno)
    $stmtRankingImoveis = $pdo->prepare("
        SELECT t.property_name as imovel, COUNT(t.id) as total 
        FROM tasks t 
        $where
        GROUP BY t.property_name
        ORDER BY total DESC
    ");
    $stmtRankingImoveis->execute($params);
    $rankingImoveisAll = $stmtRankingImoveis->fetchAll();
    $totalImoveis = array_sum(array_map(fn($r) => (int)$r['total'], $rankingImoveisAll));
    foreach ($rankingImoveisAll as &$row) {
        $row['percent'] = $totalImoveis > 0 ? round(((int)$row['total'] / $totalImoveis) * 100, 1) : 0;
    }
    unset($row);

    // 4. Ranking Devolutivas (conta atendimentos por devolutiva + %)
    $stmtRankingDevolutivas = $pdo->prepare("
        SELECT tu.devolutiva as devolutiva, COUNT(tu.id) as total
        FROM task_updates tu
        INNER JOIN tasks t ON t.id = tu.task_id
        $where
        AND tu.devolutiva IS NOT NULL AND tu.devolutiva <> ''
        GROUP BY tu.devolutiva
        ORDER BY total DESC
    ");
    $stmtRankingDevolutivas->execute($params);
    $rankingDevolutivas = $stmtRankingDevolutivas->fetchAll();
    $totalComDevolutiva = array_sum(array_map(fn($r) => (int)$r['total'], $rankingDevolutivas));
    foreach ($rankingDevolutivas as &$row) {
        $row['percent'] = $totalComDevolutiva > 0 ? round(((int)$row['total'] / $totalComDevolutiva) * 100, 1) : 0;
    }
    unset($row);

    // 5. Resumo do dia (contagens rápidas; escopo segue o papel: admin = equipe, demais = próprio)
    $stmtSum = $pdo->prepare("
        SELECT
            SUM(t.status <> 'done') AS abertas,
            SUM(t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date <> '0000-00-00' AND t.due_date < CURDATE()) AS vencidas,
            SUM(t.status <> 'done' AND t.due_date >= CURDATE() AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS vencem_7d
        FROM tasks t $where
    ");
    $stmtSum->execute($params);
    $summary = $stmtSum->fetch() ?: ['abertas' => 0, 'vencidas' => 0, 'vencem_7d' => 0];
    $stmtProm = $pdo->prepare("
        SELECT COUNT(*) FROM task_updates tu
        INNER JOIN tasks t ON t.id = tu.task_id
        $where AND tu.devolutiva = 'Promessa de pagamento'
    ");
    $stmtProm->execute($params);
    $summary['promessas'] = (int)$stmtProm->fetchColumn();
    $summary = array_map(fn($v) => (int)$v, $summary);

    // 6. Exportação (download direto; mesmas queries/permissões acima)
    // type: um ou vários separados por vírgula (ranking_atendentes|ranking_imoveis|ranking_devolutivas|tasks|todas)
    // format: xls (padrão, tabelas estilizadas) | csv (cru, com BOM e ;)
    if (($_GET['action'] ?? '') === 'export') {
        $rawType = $_GET['type'] ?? '';
        $format = strtolower($_GET['format'] ?? 'xls');
        $allowed = ['ranking_atendentes', 'ranking_imoveis', 'ranking_devolutivas', 'tasks'];
        if ($rawType === 'todas') {
            $wanted = ['ranking_devolutivas', 'ranking_atendentes', 'ranking_imoveis'];
        } else {
            $wanted = array_values(array_intersect(explode(',', $rawType), $allowed));
            if (empty($wanted)) {
                jsonResponse(['success' => false, 'error' => 'Tipo inválido.'], 400);
            }
        }
        if (!in_array($format, ['xls', 'csv'], true)) $format = 'xls';

        $buildRanking = function ($nameKey, $list) {
            return [
                'header' => [$nameKey, 'total', 'percentual'],
                'rows' => array_map(fn($r) => [$r[$nameKey] ?? 'Desconhecido', $r['total'], str_replace('.', ',', (string)$r['percent'])], $list),
            ];
        };
        $sections = [];
        // Ordem fixa dos blocos (previsível independente da ordem marcada)
        $orderBlocks = ['ranking_devolutivas', 'ranking_atendentes', 'ranking_imoveis', 'tasks'];
        foreach ($orderBlocks as $block) {
            if (!in_array($block, $wanted, true)) continue;
            if ($block === 'tasks') {
                $stmtExp = $pdo->prepare("SELECT property_name, client_name, status, due_date FROM tasks t $where ORDER BY created_at DESC LIMIT 5000");
                $stmtExp->execute($params);
                $sections[] = [
                    'title' => 'Tarefas',
                    'header' => ['imovel', 'cliente', 'status', 'vencimento'],
                    'rows' => array_map(fn($r) => [$r['property_name'], $r['client_name'], $r['status'], $r['due_date']], $stmtExp->fetchAll()),
                ];
            } elseif ($block === 'ranking_devolutivas') {
                $sections[] = ['title' => 'Ranking de Devolutivas'] + $buildRanking('devolutiva', $rankingDevolutivas);
            } elseif ($block === 'ranking_atendentes') {
                $sections[] = ['title' => 'Ranking de Atendentes'] + $buildRanking('atendente', $rankingAtendentes);
            } elseif ($block === 'ranking_imoveis') {
                $sections[] = ['title' => 'Ranking de Imóveis'] + $buildRanking('imovel', $rankingImoveisAll);
            }
        }

        if (ob_get_length()) ob_clean();
        $stamp = date('Y-m-d');
        $fileTag = ($rawType === 'todas' || count($wanted) > 1) ? 'combinado' : $wanted[0];
        if ($format === 'csv') {
            // CSV cru: só a 1ª seção (CSV não comporta blocos)
            $sec = $sections[0];
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fileTag . '_' . $stamp . '.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, $sec['header'], ';');
            foreach ($sec['rows'] as $row) fputcsv($out, $row, ';');
            fclose($out);
            exit;
        }
        // XLS de verdade (SpreadsheetML: Excel e Google Sheets abrem com abas e estilo)
        $escXml = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $sheetName = fn($t) => mb_substr(preg_replace('/[\\\\\\/\\?\\*:\\[\\]]+/', '', $t), 0, 31);
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="relatorio_' . $fileTag . '_' . $stamp . '.xls"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        echo '<Styles><Style ss:ID="sHeader"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1B3A2A" ss:Pattern="Solid"/></Style>';
        echo '<Style ss:ID="sTotal"><Font ss:Bold="1"/></Style></Styles>';
        foreach ($sections as $sec) {
            echo '<Worksheet ss:Name="' . $escXml($sheetName($sec['title'])) . '"><Table>';
            echo '<Row>';
            foreach ($sec['header'] as $h) echo '<Cell ss:StyleID="sHeader"><Data ss:Type="String">' . $escXml($h) . '</Data></Cell>';
            echo '</Row>';
            $sum = 0;
            foreach ($sec['rows'] as $row) {
                echo '<Row>';
                foreach ($row as $i => $cell) {
                    $num = ($i === 1 && is_numeric($cell));
                    if ($num) $sum += $cell;
                    echo '<Cell><Data ss:Type="' . ($num ? 'Number' : 'String') . '">' . $escXml($cell) . '</Data></Cell>';
                }
                echo '</Row>';
            }
            if (count($sec['header']) === 3) {
                echo '<Row><Cell ss:StyleID="sTotal"><Data ss:Type="String">TOTAL</Data></Cell><Cell ss:StyleID="sTotal"><Data ss:Type="Number">' . $sum . '</Data></Cell><Cell><Data ss:Type="String"></Data></Cell></Row>';
            } else {
                echo '<Row><Cell ss:StyleID="sTotal"><Data ss:Type="String">Total de linhas: ' . count($sec['rows']) . '</Data></Cell></Row>';
            }
            echo '</Table></Worksheet>';
        }
        echo '</Workbook>';
        exit;
    }

    jsonResponse([
        'success' => true,
        'data' => [
            'total_atendimentos' => $totalAtendimentos,
            'my_summary' => $summary,
            'ranking_atendentes' => array_slice($rankingAtendentes, 0, 5),
            'ranking_atendentes_all' => $rankingAtendentes,
            'ranking_imoveis' => array_slice($rankingImoveisAll, 0, 5),
            'ranking_imoveis_all' => $rankingImoveisAll,
            'ranking_devolutivas' => array_slice($rankingDevolutivas, 0, 5),
            'ranking_devolutivas_all' => $rankingDevolutivas
        ]
    ]);
    
} catch (Exception $e) {
    jsonResponse(['error' => 'Erro ao gerar relatórios'], 500);
}
?>
