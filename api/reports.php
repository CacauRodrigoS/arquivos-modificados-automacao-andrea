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
