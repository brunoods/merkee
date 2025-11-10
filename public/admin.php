<?php
// ---
// /public/admin.php
// (VERSÃO 5.0 - CORRIGIDA E COMPLETA)
// ---

// 1. Incluir Arquivo ÚNICO de Bootstrap
// (Carrega .env, autoloader e getDbConnection)
require_once __DIR__ . '/../config/bootstrap.php';

session_start();

// --- Funções de Login/Logout (Modificadas para HASH) ---
// --- (Dentro de merkee/public/admin.php) ---

function login() {
    if (!empty($_POST['senha'])) {
        
        // --- (A CORREÇÃO ESTÁ AQUI) ---
        // Tenta ler do $_ENV primeiro (para evitar cache) e depois do getenv()
        $hashCorreto = $_ENV['ADMIN_PASSWORD_HASH'] ?? getenv('ADMIN_PASSWORD_HASH');
        
        if (empty($hashCorreto)) {
            return "Erro de configuração: ADMIN_PASSWORD_HASH não definido no .env (ou o cache precisa de ser limpo)";
        }

        // Verifica a senha enviada contra o hash
        if (password_verify($_POST['senha'], $hashCorreto)) {
            $_SESSION['admin_logado'] = true;
            header("Location: admin.php");
            exit;
        } else {
            return "Senha incorreta!";
        }
    }
    return null;
}

function logout() {
    unset($_SESSION['admin_logado']);
    header("Location: admin.php");
    exit;
}


$feedback = null;
$dashboard_stats = [];
$usuarios = [];
$usuario_para_detalhes = null;
$user_dashboard_stats = [];
$acao = $_GET['acao'] ?? 'dashboard';

// (Este try-catch é novo, para apanhar erros de DB e não quebrar a página)
try {
    if (isset($_SESSION['admin_logado']) && $_SESSION['admin_logado'] === true) {
        
        $pdo = getDbConnection(); // (Vem do bootstrap.php)

        // --- LÓGICA DE AÇÕES (POST - SALVAR) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_form']) && $_POST['acao_form'] === 'salvar_usuario') {
            $id = (int)$_POST['id_usuario_modal'];
            $nome = trim($_POST['nome_modal']);
            $whatsapp_id = trim($_POST['whatsapp_id_modal']);
            $observacoes = trim($_POST['observacoes_modal']);

            $stmt = $pdo->prepare(
                "UPDATE usuarios SET nome = ?, whatsapp_id = ?, observacoes = ? WHERE id = ?"
            );
            $stmt->execute([$nome, $whatsapp_id, $observacoes, $id]);
            $feedback = "Utilizador #{$id} atualizado com sucesso!";
            $acao = 'dashboard';
        }

        // --- LÓGICA DE AÇÕES (GET - Logout, Detalhes, e Gestão de Subscrição) ---
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if ($acao === 'logout') {
                logout();
            }

            // (NOVO!) Adicionar tempo à subscrição
            if (isset($_GET['add_tempo'])) {
                $id = (int)$_GET['add_tempo'];
                $dias = (int)$_GET['dias'];
                
                // Busca a data de expiração atual
                $stmt_data = $pdo->prepare("SELECT data_expiracao FROM usuarios WHERE id = ?");
                $stmt_data->execute([$id]);
                $data_atual_str = $stmt_data->fetchColumn();
                
                $data_base = new DateTime(); // Hoje
                $data_atual = $data_atual_str ? new DateTime($data_atual_str) : null;

                // Se a subscrição atual AINDA ESTIVER VÁLIDA, adiciona tempo a ela
                if ($data_atual && $data_atual > $data_base) {
                    $data_base = $data_atual;
                }
                // Se expirou ou é nova, começa a contar de HOJE

                $data_base->add(new DateInterval("P{$dias}D")); // Adiciona os dias
                $nova_data_expiracao = $data_base->format('Y-m-d H:i:s'); // (Melhor usar H:i:s)

                // Ativa o utilizador e define a nova data
                $stmt_update = $pdo->prepare("UPDATE usuarios SET is_ativo = 1, data_expiracao = ? WHERE id = ?");
                $stmt_update->execute([$nova_data_expiracao, $id]);
                
                $feedback = "Subscrição do Utilizador #{$id} estendida até " . $data_base->format('d/m/Y');
                $acao = 'dashboard';
            }
            
            // (NOVO!) Revogar subscrição
            if (isset($_GET['revogar'])) {
                $id = (int)$_GET['revogar'];
                // Define a data como NULL (expirada) e desativa
                $stmt = $pdo->prepare("UPDATE usuarios SET is_ativo = 0, data_expiracao = NULL WHERE id = ?");
                $stmt->execute([$id]);
                $feedback = "Subscrição do Utilizador #{$id} REVOGADA com sucesso.";
                $acao = 'dashboard';
            }
        }


        // --- CARREGAR DADOS PARA AS VISTAS ---
        
        // (NOVO!) VISTA DE DETALHES DO UTILIZADOR
        if ($acao === 'detalhes') {
            $id_usuario = (int)($_GET['id'] ?? 0);
            if ($id_usuario == 0) {
                 throw new Exception("ID de usuário inválido.");
            }
            
            // 1. Info Básica
            $stmt_user = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
            $stmt_user->execute([$id_usuario]);
            $usuario_para_detalhes = $stmt_user->fetch();
            
            if (!$usuario_para_detalhes) {
                 throw new Exception("Usuário #{$id_usuario} não encontrado.");
            }

            // 2. Stats de Compras
            $stmt_compras = $pdo->prepare("SELECT COUNT(*) as total_compras, SUM(total_gasto) as total_valor FROM compras WHERE usuario_id = ? AND status = 'finalizada'");
            $stmt_compras->execute([$id_usuario]);
            $stats_compras = $stmt_compras->fetch();
            $user_dashboard_stats['total_compras'] = $stats_compras['total_compras'] ?? 0;
            $user_dashboard_stats['total_gasto'] = $stats_compras['total_valor'] ?? 0;

            // 3. Stats de Poupança
            $stmt_poupanca = $pdo->prepare(
                "SELECT SUM((i.preco_normal - i.preco) * i.quantidade) as total_poupanca 
                 FROM itens_compra i
                 JOIN compras c ON i.compra_id = c.id
                 WHERE c.usuario_id = ? AND i.em_promocao = 1 AND i.preco_normal > preco"
            );
            $stmt_poupanca->execute([$id_usuario]);
            $stats_poupanca = $stmt_poupanca->fetch();
            $user_dashboard_stats['total_poupanca'] = $stats_poupanca['total_poupanca'] ?? 0;
        
        } 
        // VISTA PRINCIPAL (DASHBOARD GLOBAL + LISTA DE UTILIZADORES)
        else { 
            // 1. STATS GLOBAIS: Utilizadores
            // (Query corrigida para incluir data_expiracao)
            $stats = $pdo->query("
                SELECT 
                    COUNT(*) as total, 
                    SUM(CASE WHEN is_ativo = 1 AND (data_expiracao IS NULL OR data_expiracao >= NOW()) THEN 1 ELSE 0 END) as ativos 
                FROM usuarios
            ")->fetch();
            $dashboard_stats['total_users'] = $stats['total'] ?? 0;
            $dashboard_stats['active_users'] = $stats['ativos'] ?? 0;
            $dashboard_stats['inactive_users'] = $dashboard_stats['total_users'] - $dashboard_stats['active_users'];

            // 2. STATS GLOBAIS: Compras
            $stats_compras = $pdo->query("SELECT COUNT(*) as total_compras, SUM(total_gasto) as total_valor FROM compras WHERE status = 'finalizada'")->fetch();
            $dashboard_stats['total_compras'] = $stats_compras['total_compras'] ?? 0;
            $dashboard_stats['total_gasto'] = $stats_compras['total_valor'] ?? 0;

            // 3. STATS GLOBAIS: Poupança
            $stats_poupanca = $pdo->query("SELECT SUM((preco_normal - preco) * quantidade) as total_poupanca FROM itens_compra WHERE em_promocao = 1 AND preco_normal > preco")->fetch();
            $dashboard_stats['total_poupanca'] = $stats_poupanca['total_poupanca'] ?? 0;
            
            // 4. Lista de Utilizadores (agora carrega data_expiracao)
            $usuarios = $pdo->query("SELECT id, nome, whatsapp_id, is_ativo, criado_em, observacoes, data_expiracao FROM usuarios ORDER BY criado_em DESC")->fetchAll();
        }

    } else {
        $feedback = login();
    }

} catch (Exception $e) {
    // Se algo falhar (ex: DB offline), mostra o erro
    $feedback = "ERRO CRÍTICO: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin - Merkee</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; background: #f4f4f4; padding: 20px; }
        .container { max-width: 1300px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; border-bottom: 2px solid #eee; padding-bottom: 5px; }
        .logout { float: right; text-decoration: none; color: #d9534f; font-weight: bold; }
        .feedback { padding: 10px; background: #dff0d8; border: 1px solid #d6e9c6; color: #3c763d; margin-bottom: 15px; border-radius: 4px; }
        .feedback.error { background: #f2dede; border-color: #ebccd1; color: #a94442; }
        
        /* Dashboard */
        .dashboard { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .dash-card { background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 5px; }
        .dash-card h3 { margin: 0 0 5px 0; font-size: 16px; color: #555; }
        .dash-card p { margin: 0; font-size: 24px; font-weight: bold; color: #007bff; }
        .dash-card p.poupanca { color: #5cb85c; }
        .dash-card p.inativos { color: #d9534f; }
        .dash-card.user-card { background: #e6f7ff; border-color: #b3e0ff; }

        /* Tabela e Pesquisa */
        .search-box { margin: 15px 0; }
        .search-box label { font-weight: bold; margin-right: 10px; }
        .search-box input { padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 300px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background-color: #f9f9f9; }
        td { word-break: break-word; }
        .acao-link { display: inline-block; padding: 5px 10px; text-decoration: none; border-radius: 4px; color: #fff; font-weight: bold; margin: 2px; font-size: 12px; cursor: pointer; }
        .editar { background-color: #007bff; }
        .detalhes { background-color: #6c757d; }
        .add-tempo { background-color: #f0ad4e; }
        .revogar { background-color: #d9534f; }
        .status-ativo { color: #5cb85c; font-weight: bold; }
        .status-expirado { color: #d9534f; font-weight: bold; }
        .status-inativo { color: #777; }
        
        /* Ações de Tempo */
        .acoes-tempo a { font-size: 12px; color: #007bff; text-decoration: none; margin: 0 2px; }
        .acoes-tempo a:hover { text-decoration: underline; }

        /* Formulário, Modal, Login */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group textarea { width: 95%; max-width: 500px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .form-group textarea { min-height: 80px; }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; color: #fff; cursor: pointer; }
        .btn-primary { background: #007bff; }
        .btn-secondary { background: #6c757d; text-decoration: none; display: inline-block; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 25px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 8px; position: relative; }
        .modal-close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .modal-close:hover, .modal-close:focus { color: #000; }
        .login-form { text-align: center; max-width: 400px; margin: 50px auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    
    <div class="container">
        
        <?php if (isset($_SESSION['admin_logado']) && $_SESSION['admin_logado'] === true): ?>
            
            <a href="?acao=logout" class="logout">Sair</a>
            <h1>Painel Admin Merkee</h1>

            <?php if ($feedback): ?>
                <div class="feedback <?php echo str_starts_with($feedback, 'ERRO') ? 'error' : ''; ?>"><?php echo htmlspecialchars($feedback); ?></div>
            <?php endif; ?>
            
            
            <?php if ($acao === 'detalhes' && $usuario_para_detalhes): ?>
                <h2>
                    <a href="?acao=dashboard" class="btn-secondary" style="font-size: 14px; padding: 5px 10px;">&larr; Voltar</a>
                    Detalhes do Utilizador
                </h2>
                
                <div class="dash-card user-card">
                    <h3><?php echo htmlspecialchars($usuario_para_detalhes['nome']); ?></h3>
                    <p style="font-size: 16px; font-weight: normal;">
                        ID: <?php echo $usuario_para_detalhes['id']; ?><br>
                        WhatsApp: <?php echo htmlspecialchars($usuario_para_detalhes['whatsapp_id']); ?><br>
                        Membro desde: <?php echo (new DateTime($usuario_para_detalhes['criado_em']))->format('d/m/Y'); ?><br>
                        Observações: <?php echo htmlspecialchars($usuario_para_detalhes['observacoes']); ?>
                    </p>
                </div>
                
                <h2>Estatísticas do Utilizador</h2>
                <div class="dashboard">
                    <div class="dash-card">
                        <h3>Compras Finalizadas</h3>
                        <p><?php echo $user_dashboard_stats['total_compras']; ?></p>
                    </div>
                    <div class="dash-card">
                        <h3>Valor Gasto</h3>
                        <p>R$ <?php echo number_format($user_dashboard_stats['total_gasto'], 2, ',', '.'); ?></p>
                    </div>
                    <div class="dash-card">
                        <h3>Poupança Total</h3>
                        <p class="poupanca">R$ <?php echo number_format($user_dashboard_stats['total_poupanca'], 2, ',', '.'); ?></p>
                    </div>
                </div>

                
            <?php else: ?>
                <h2>Dashboard Global</h2>
                <div class="dashboard">
                    <div class="dash-card">
                        <h3>Total de Utilizadores</h3>
                        <p><?php echo $dashboard_stats['total_users']; ?></p>
                    </div>
                    <div class="dash-card">
                        <h3>Utilizadores Ativos</h3>
                        <p><?php echo $dashboard_stats['active_users']; ?></p>
                    </div>
                    <div class="dash-card">
                        <h3>Utilizadores Inativos</h3>
                        <p class="inativos"><?php echo $dashboard_stats['inactive_users']; ?></p>
                    </div>
                    <div class="dash-card">
                        <h3>Total de Compras</h3>
                        <p><?php echo $dashboard_stats['total_compras']; ?></p>
                    </div>
                    <div class="dash-card">
                        <h3>Valor Transacionado</h3>
                        <p>R$ <?php echo number_format($dashboard_stats['total_gasto'], 2, ',', '.'); ?></p>
                    </div>
                    <div class="dash-card">
                        <h3>Poupança Global</h3>
                        <p class="poupanca">R$ <?php echo number_format($dashboard_stats['total_poupanca'], 2, ',', '.'); ?></p>
                    </div>
                </div>
                
                <h2>Gestão de Utilizadores</h2>
                
                <div class="search-box">
                    <label for="searchInput">Pesquisar:</label>
                    <input type="text" id="searchInput" onkeyup="filtrarTabela()" placeholder="Filtrar por nome ou WhatsApp ID...">
                </div>

                <table id="userTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>WhatsApp ID</th>
                            <th>Status</th>
                            <th>Membro Desde</th>
                            <th>Observações</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $user): ?>
                            <?php
                                // Lógica de Status
                                $status_class = 'status-inativo';
                                $status_text = 'Inativo';
                                $data_exp = $user['data_expiracao'] ? new DateTime($user['data_expiracao']) : null;
                                
                                if ($user['is_ativo'] && $data_exp && $data_exp >= new DateTime()) {
                                    $status_class = 'status-ativo';
                                    $status_text = 'Ativo (Expira ' . $data_exp->format('d/m/Y') . ')';
                                } elseif ($user['is_ativo'] && $data_exp && $data_exp < new DateTime()) {
                                    $status_class = 'status-expirado';
                                    $status_text = 'Expirado (em ' . $data_exp->format('d/m/Y') . ')';
                                }
                            ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['nome']); ?></td>
                                <td><?php echo htmlspecialchars($user['whatsapp_id']); ?></td>
                                <td><span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                <td><?php echo (new DateTime($user['criado_em']))->format('d/m/Y'); ?></td>
                                <td style="max-width: 200px;"><?php echo htmlspecialchars($user['observacoes']); ?></td>
                                <td>
                                    <span class="acao-link editar btn-edit" 
                                          data-id="<?php echo $user['id']; ?>"
                                          data-nome="<?php echo htmlspecialchars($user['nome']); ?>"
                                          data-whatsapp="<?php echo htmlspecialchars($user['whatsapp_id']); ?>"
                                          data-observacoes="<?php echo htmlspecialchars($user['observacoes']); ?>">
                                        Editar
                                    </span>
                                    
                                    <a href="?acao=detalhes&id=<?php echo $user['id']; ?>" class="acao-link detalhes">
                                        Detalhes
                                    </a>
                                    
                                    <a href="?revogar=<?php echo $user['id']; ?>" class="acao-link revogar" onclick="return confirm('Revogar permanentemente a subscrição deste utilizador?');">
                                        Revogar
                                    </a>
                                    
                                    <div class="acoes-tempo">
                                        Adicionar:
                                        <a href="?add_tempo=<?php echo $user['id']; ?>&dias=7" onclick="return confirm('Adicionar 7 dias?');">+7d</a>
                                        <a href="?add_tempo=<?php echo $user['id']; ?>&dias=30" onclick="return confirm('Adicionar 30 dias?');">+30d</a>
                                        <a href="?add_tempo=<?php echo $user['id']; ?>&dias=90" onclick="return confirm('Adicionar 90 dias?');">+90d</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
            <?php endif; ?>


        <?php else: ?>
            
            <form action="admin.php" method="POST" class="login-form">
                <h2>Login - Painel Admin</h2>
                
                <?php if ($feedback && !isset($_SESSION['admin_logado'])): ?>
                    <div class="feedback error"><?php echo htmlspecialchars($feedback); ?></div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="senha">Senha</label>
                   <input type="password" name="senha" id="senha" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary">Entrar</button>
            </form>

        <?php endif; ?>
        </div> <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="modal-close">&times;</span>
            <h2>Editar Utilizador</h2>
            <form action="admin.php?acao=dashboard" method="POST">
                <input type="hidden" name="acao_form" value="salvar_usuario">
                <input type="hidden" name="id_usuario_modal" id="form-id">
                
                <div class="form-group">
                    <label for="form-nome">Nome</label>
                    <input type="text" id="form-nome" name="nome_modal">
                </div>
                <div class="form-group">
                    <label for="form-whatsapp">WhatsApp ID</label>
                    <input type="text" id="form-whatsapp" name="whatsapp_id_modal">
                </div>
                <div class="form-group">
                    <label for="form-observacoes">Observações</label>
                    <textarea id="form-observacoes" name="observacoes_modal"></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">Salvar Alterações</button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            
            // --- LÓGICA DO MODAL ---
            var modal = document.getElementById("editModal");
            var spanClose = document.getElementsByClassName("modal-close")[0];
            
            var editButtons = document.getElementsByClassName("btn-edit");
            for (var i = 0; i < editButtons.length; i++) {
                editButtons[i].onclick = function() {
                    // Preenche o formulário do modal com os dados do botão
                    document.getElementById('form-id').value = this.dataset.id;
                    document.getElementById('form-nome').value = this.dataset.nome;
                    document.getElementById('form-whatsapp').value = this.dataset.whatsapp;
                    document.getElementById('form-observacoes').value = this.dataset.observacoes;
                    modal.style.display = "block"; 
                }
            }
            
            if (spanClose) {
                spanClose.onclick = function() {
                    modal.style.display = "none";
                }
            }
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = "none";
                }
            }

        }); // Fim do DOMContentLoaded

        // --- LÓGICA DA PESQUISA (Filtro) ---
        function filtrarTabela() {
            var input, filter, table, tr, tdNome, tdWhatsApp, i, txtValueNome, txtValueWhatsApp;
            input = document.getElementById("searchInput");
            filter = input.value.toLowerCase();
            table = document.getElementById("userTable");
            // (Evita erro se a tabela não existir)
            if (!table) return; 
            tr = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");

            for (i = 0; i < tr.length; i++) {
                tdNome = tr[i].getElementsByTagName("td")[1]; // Coluna "Nome"
                tdWhatsApp = tr[i].getElementsByTagName("td")[2]; // Coluna "WhatsApp ID"
                
                if (tdNome || tdWhatsApp) {
                    txtValueNome = tdNome.textContent || tdNome.innerText;
                    txtValueWhatsApp = tdWhatsApp.textContent || tdWhatsApp.innerText;
                    
                    if (txtValueNome.toLowerCase().indexOf(filter) > -1 || 
                        txtValueWhatsApp.toLowerCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }
    </script>

</body>
</html>