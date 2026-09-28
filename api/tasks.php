<?php
// api/tasks.php
require_once 'auth_middleware.php';
$user_id = getCurrentUserId();

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $pdo = getConnection();
    // Paginação: carrega de 50 em 50 (evita despejar 1000+ tarefas de uma vez)
    // Aceita `limit`+`offset` ou `page`+`limit` (page começa em 1)
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    if (isset($_GET['page'])) {
        $page = max(1, (int)$_GET['page']);
        $offset = ($page - 1) * $limit;
    }
    if ($limit < 1) $limit = 50;
    if ($limit > 200) $limit = 200;
    if ($offset < 0) $offset = 0;
    $search = trim($_GET['search'] ?? '');
    $baseWhere = "WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    $params = [];
    if ($search !== '') {
        $baseWhere .= " AND (property_name LIKE ? OR client_name LIKE ? OR client_code LIKE ?)";
        $like = '%' . $search . '%';
        $params = [$like, $like, $like];
    }
    // Filtro por status (whitelist; 'all' ou inválido = sem filtro)
    $status = $_GET['status'] ?? 'all';
    if (in_array($status, ['todo', 'in_progress', 'done'], true)) {
        $baseWhere .= " AND status = ?";
        $params[] = $status;
    }
    // S1: não-admin só vê o que é seu (dono ou compartilhado), igual ao reports.php/list_trash
    if (getCurrentUserRole() !== 'admin') {
        $baseWhere .= " AND (assigned_to = ? OR id IN (SELECT task_id FROM task_shares WHERE user_id = ?))";
        $params[] = $user_id;
        $params[] = $user_id;
    }
    // Total (mesmo filtro) para o frontend saber se há mais
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tasks $baseWhere");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
    // Ordenação: 'recent' (padrão) ou 'critical' (vencidas primeiro)
    $order = $_GET['order'] ?? 'recent';
    if ($order === 'critical') {
        $orderSql = "CASE WHEN status = 'done' THEN 1 ELSE 0 END, CASE WHEN due_date IS NULL OR due_date = '0000-00-00' THEN 1 ELSE 0 END, due_date ASC";
    } else {
        $orderSql = "created_at DESC";
    }
    // Página atual
    $stmt = $pdo->prepare("SELECT * FROM tasks $baseWhere ORDER BY $orderSql LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();

    // Buscar compartilhamentos SOMENTE das tarefas retornadas (evita scan cheio)
    $shares = [];
    $taskIds = array_column($tasks, 'id');
    if (!empty($taskIds)) {
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmtShares = $pdo->prepare("SELECT task_id, user_id FROM task_shares WHERE task_id IN ($placeholders)");
        $stmtShares->execute($taskIds);
        $shares = $stmtShares->fetchAll();
    }

    // Mapear compartilhamentos para as tarefas
    foreach ($tasks as &$task) {
        $task['shared_with'] = [];
        foreach ($shares as $share) {
            if ($share['task_id'] == $task['id']) {
                $task['shared_with'][] = (string)$share['user_id']; // Javascript espera string na nossa logica
            }
        }
        // Aliases to match JS mocks if necessary (mock had 'name' instead of property_name)
        // JS mock usa t.name e t.client, então vamos criar alias
        $task['name'] = $task['property_name'];
        $task['client'] = $task['client_name'];
        $task['type'] = 'Cobrança'; // mock fallback
    }

    jsonResponse(['success' => true, 'tasks' => $tasks, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'hasMore' => ($offset + count($tasks)) < $total]);
}

if ($action === 'get') {
    $pdo = getConnection();
    $task_id = $_GET['id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    if (!$task) {
        jsonResponse(['error' => 'Tarefa não encontrada.'], 404);
    }
    $stmtShares = $pdo->prepare("SELECT user_id FROM task_shares WHERE task_id = ?");
    $stmtShares->execute([$task_id]);
    $task['shared_with'] = array_map('strval', $stmtShares->fetchAll(PDO::FETCH_COLUMN));
    $task['name'] = $task['property_name'];
    $task['client'] = $task['client_name'];
    $task['type'] = 'Cobrança';
    jsonResponse(['success' => true, 'task' => $task]);
}

if ($action === 'list_trash') {
    $pdo = getConnection();
    if (getCurrentUserRole() === 'admin') {
        $stmt = $pdo->query("SELECT * FROM tasks WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
        $tasks = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT DISTINCT t.* FROM tasks t LEFT JOIN task_shares s ON s.task_id = t.id AND s.user_id = ? WHERE t.deleted_at IS NOT NULL AND (t.assigned_to = ? OR s.user_id = ?) ORDER BY t.deleted_at DESC");
        $stmt->execute([$user_id, $user_id, $user_id]);
        $tasks = $stmt->fetchAll();
    }
    jsonResponse(['success' => true, 'tasks' => $tasks]);
}

if ($action === 'soft_delete') {
    requireCsrf();
    $pdo = getConnection();
    $task_id = $_POST['task_id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $stmt = $pdo->prepare("UPDATE tasks SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$task_id]);
    jsonResponse(['success' => true]);
}

if ($action === 'restore') {
    requireCsrf();
    $pdo = getConnection();
    $task_id = $_POST['task_id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $stmt = $pdo->prepare("UPDATE tasks SET deleted_at = NULL WHERE id = ?");
    $stmt->execute([$task_id]);
    jsonResponse(['success' => true]);
}

if ($action === 'force_delete') {
    requireCsrf();
    $pdo = getConnection();
    $task_id = $_POST['task_id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    jsonResponse(['success' => true]);
}

if ($action === 'truncate_tasks') {
    requireAdmin();
    requireCsrf();
    $pdo = getConnection();
    try {
        $pdo->query("DELETE FROM task_shares");
        $pdo->query("DELETE FROM tasks");
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => 'Erro ao limpar tarefas.']);
    }
}

if ($action === 'create') {
    requireCsrf();
    // Distribuir tarefas
    $data = json_decode(file_get_contents("php://input"), true);
    
    if(!isset($data['tasks']) || !isset($data['assigned_to'])) {
        jsonResponse(['error' => 'Dados inválidos'], 400);
    }

    // S17: teto anti-DoS — ninguém cria 10 mil de uma vez e estoura timeout/memória
    if (!is_array($data['tasks']) || count($data['tasks']) < 1 || count($data['tasks']) > 500) {
        jsonResponse(['success' => false, 'error' => 'Envie entre 1 e 500 tarefas por vez.'], 400);
    }
    
    $assigned_to = $data['assigned_to'];
    
    $pdo = getConnection();
    $assigned_to = assertUserExists($pdo, $assigned_to);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO tasks (property_name, client_code, client_name, value, due_date, assigned_to, created_by, bloco, apto, situacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach($data['tasks'] as $task) {
            $stmt->execute([
                $task['property_name'],
                $task['client_code'],
                $task['client_name'],
                $task['value'],
                $data['due_date'] ?? null,
                $assigned_to,
                $user_id,
                $task['bloco'] ?? null,
                $task['apto'] ?? null,
                $task['situacao'] ?? null
            ]);
        }
        $pdo->commit();
        jsonResponse(['success' => true]);
    } catch(Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Erro ao salvar tarefas'], 500);
    }
}

if ($action === 'import_bulk') {
    requireCsrf();
    $data = json_decode(file_get_contents("php://input"), true);
    
    if(!isset($data['tasks']) || !is_array($data['tasks'])) {
        jsonResponse(['error' => 'Dados inválidos'], 400);
    }

    // S17: mesmo teto na importação via planilha
    if (count($data['tasks']) < 1 || count($data['tasks']) > 500) {
        jsonResponse(['success' => false, 'error' => 'Envie entre 1 e 500 tarefas por vez.'], 400);
    }
    
    $pdo = getConnection();
    // Valida todos os responsáveis antes de abrir a transação
    $assignees = [];
    foreach ($data['tasks'] as $task) {
        $assignees[] = assertUserExists($pdo, $task['assigned_to'] ?? $user_id);
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO tasks (property_name, client_code, client_name, value, due_date, assigned_to, created_by, bloco, apto, observations, situacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach($data['tasks'] as $i => $task) {
            $stmt->execute([
                $task['property_name'] ?? '',
                $task['client_code'] ?? '',
                $task['client_name'] ?? '',
                $task['value'] ?? 0,
                !empty($task['due_date']) ? $task['due_date'] : null,
                $assignees[$i],
                $user_id,
                $task['bloco'] ?? null,
                $task['apto'] ?? null,
                $task['observations'] ?? null,
                $task['situacao'] ?? null
            ]);
        }
        $pdo->commit();
        jsonResponse(['success' => true]);
    } catch(Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Erro ao importar tarefas'], 500);
    }
}

if ($action === 'update_status') {
    requireCsrf();
    $pdo = getConnection();
    $task_id = $_POST['task_id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $status = $_POST['status'] ?? '';

    // S2: só aceita os 3 status que o Kanban conhece
    $allowedStatuses = ['todo', 'in_progress', 'done'];
    if (!in_array($status, $allowedStatuses, true)) {
        jsonResponse(['success' => false, 'error' => 'Status inválido.'], 400);
    }
    
    $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
    $stmt->execute([$status, $task_id]);
    
    jsonResponse(['success' => true]);
}

if ($action === 'add_update') {
    requireCsrf();
    // Adicionar Atendimento
    $pdo = getConnection();
    $task_id = $_POST['task_id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $content = $_POST['content'] ?? '';
    $devolutiva = trim($_POST['devolutiva'] ?? '');

    $allowedDevolutivas = [
        'Não atendeu', 'Atendeu e desligou', 'Falecido', 'Número inválido',
        'Caixa postal', 'Retornar depois', 'Recado com terceiro',
        'Negociação em andamento', 'Promessa de pagamento', 'Acordo fechado',
        'Pagamento efetuado', 'Sem interesse'
    ];
    if (!in_array($devolutiva, $allowedDevolutivas, true)) {
        jsonResponse(['success' => false, 'error' => 'Devolutiva inválida.'], 400);
    }

    $stmt = $pdo->prepare("INSERT INTO task_updates (task_id, user_id, content, devolutiva) VALUES (?, ?, ?, ?)");
    $stmt->execute([$task_id, $user_id, $content, $devolutiva]);

    // Incentivo: avisa se foi a primeira devolutiva do usuário (frontend celebra)
    $stmtFirst = $pdo->prepare("SELECT COUNT(*) FROM task_updates WHERE user_id = ? AND devolutiva IS NOT NULL AND devolutiva <> ''");
    $stmtFirst->execute([$user_id]);
    $isFirstDevolutiva = ((int)$stmtFirst->fetchColumn() === 1);
    
    // Se o status for todo, muda para in_progress automaticamente
    $stmtStatus = $pdo->prepare("UPDATE tasks SET status = 'in_progress' WHERE id = ? AND status = 'todo'");
    $stmtStatus->execute([$task_id]);
    
    jsonResponse(['success' => true, 'first_devolutiva' => $isFirstDevolutiva]);
}

if ($action === 'reassign') {
    requireCsrf();
    $pdo = getConnection();
    $task_id = $_POST['task_id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $new_user_id = $_POST['user_id'] ?? 0;
    $new_user_id = assertUserExists($pdo, $new_user_id);
    
    // Update owner and remove from shares if they were shared
    $stmt = $pdo->prepare("UPDATE tasks SET assigned_to = ? WHERE id = ?");
    $stmt->execute([$new_user_id, $task_id]);
    
    $stmtDel = $pdo->prepare("DELETE FROM task_shares WHERE task_id = ? AND user_id = ?");
    $stmtDel->execute([$task_id, $new_user_id]);
    
    jsonResponse(['success' => true]);
}

if ($action === 'share_task') {
    requireCsrf();
    $pdo = getConnection();
    $task_id = $_POST['task_id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $user_id_to_share = $_POST['user_id'] ?? 0;
    $user_id_to_share = assertUserExists($pdo, $user_id_to_share);
    
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO task_shares (task_id, user_id) VALUES (?, ?)");
        $stmt->execute([$task_id, $user_id_to_share]);
        jsonResponse(['success' => true]);
    } catch(Exception $e) {
        jsonResponse(['success' => false, 'error' => 'Erro ao compartilhar tarefa.']);
    }
}

if ($action === 'unshare_task') {
    requireCsrf();
    $pdo = getConnection();
    $task_id = $_POST['task_id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $user_id_to_unshare = $_POST['user_id'] ?? 0;
    
    $stmt = $pdo->prepare("DELETE FROM task_shares WHERE task_id = ? AND user_id = ?");
    $stmt->execute([$task_id, $user_id_to_unshare]);
    
    jsonResponse(['success' => true]);
}

if ($action === 'update_details') {
    requireCsrf();
    $pdo = getConnection();
    $task_id = $_POST['task_id'] ?? 0;
    canAccessTask($pdo, $task_id);
    $start_date = $_POST['start_date'] ?? null;
    $due_date = $_POST['due_date'] ?? null;
    $observations = $_POST['observations'] ?? '';
    
    $start_date = empty($start_date) ? null : $start_date;
    $due_date = empty($due_date) ? null : $due_date;

    $stmt = $pdo->prepare("UPDATE tasks SET start_date = ?, due_date = ?, observations = ? WHERE id = ?");
    $stmt->execute([$start_date, $due_date, $observations, $task_id]);
    
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Ação inválida'], 404);
?>
