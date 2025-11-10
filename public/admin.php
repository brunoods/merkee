<?php
// ---
// /public/admin.php
// (VERSÃO 4.0 - GESTÃO DE SUBSRIÇÕES)
// ---

// --- 1. CONFIGURAÇÃO DE SEGURANÇA (MUDE ISTO!) ---
define('ADMIN_PASSWORD', 'merkee#admin@2025');
// --------------------------------------------------

session_start();
require_once __DIR__ . '/../config/db.php';

// --- Funções de Login/Logout (sem mudança) ---
function login() {
    if (!empty($_POST['senha'])) {
        if ($_POST['senha'] === ADMIN_PASSWORD) {
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

if (isset($_SESSION['admin_logado']) && $_SESSION['admin_logado'] === true) {
    
    $pdo = getDbConnection();

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
            $nova_data_expiracao = $data_base->format('Y-m-d');

            // Ativa o utilizador e define a nova data
            $stmt_update = $pdo->prepare("UPDATE usuarios SET is_ativo = 1, data_expiracao = ? WHERE id = ?");
            $stmt_update->execute([$nova_data_expiracao, $id]);
            
            $feedback = "Subscrição do Utilizador #{$id} estendida até {$nova_data_expiracao}!";
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
        $id_usuario = (int)$_GET['id'];
        
        // 1. Info Básica
        $stmt_user = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt_user->execute([$id_usuario]);
        $usuario_para_detalhes = $stmt_user->fetch();

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
        $stats = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN is_ativo = 1 THEN 1 ELSE 0 END) as ativos FROM usuarios")->fetch();
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
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background-color: #f9f9f9; }
        td { word-break: break-word; }
        .acao-link { display: inline-block; padding: 5px 10px; text-decoration: none; border-radius: 4px; color: #fff; font-weight: bold; margin: 2px; font-size: 12px; cursor: pointer; }
        /* (Removidos Ativar/Desativar) */
        .editar { background-color: #007bff; }
        .detalhes { background-color: #6c757d; }
        /* (Novos botões de tempo) */
        .add-tempo { background-color: #f0ad4e; }
        .revogar { background-color: #d9534f; }
        .status-ativo { color: #5cb85c; font-weight: bold; }
        .status-expirado { color: #d9534f; font-weight: bold; }
        .status-inativo { color: #777; }

        /* (Resto do CSS: Formulário, Modal, Login - sem mudança) */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group textarea { width: 95%; max-width: 500px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .form-group textarea { min-height: 80px; }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; color: #fff; cursor: pointer; }
        .btn-primary { background: #007bff; }
        .btn-secondary { background: #6c757d; text-decoration: none; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 25px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 8px; position: relative; }
        .modal-close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .modal-close:hover, .modal-close:focus { color: #000; }
        .login-form { text-align: center; margin-top: 50px; }
    </style>
</head>
<body>
    <div class="container">

    <?php if (isset($_SESSION['admin_logado']) && $_SESSION['admin_logado'] === true): ?>
        
        <a href="?acao=logout" class="logout">Sair</a>
        <h1>Painel de Gestão Merkee</h1>

        <?php if ($feedback): ?>
            <div class="feedback"><?php echo htmlspecialchars($feedback); ?></div>
        <?php endif; ?>

        <?php 
        // --- VISTA DE DETALHES DO UTILIZADOR ---
        if ($acao === 'detalhes' && $usuario_para_detalhes): ?>
            
            <a href="admin.php" class="btn btn-secondary" style="margin-bottom: 15px;">&larr; Voltar ao Dashboard</a>
            
            <h2>Dashboard do Utilizador: <?php echo htmlspecialchars($usuario_para_detalhes['nome']); ?></h2>
            <p><strong>ID:</strong> <?php echo $usuario_para_detalhes['id']; ?> | <strong>WhatsApp:</strong> <?php echo $usuario_para_detalhes['whatsapp_id']; ?></p>
            
            <div class="dashboard">
                <div class="dash-card user-card">
                    <h3>Compras Finalizadas</h3>
                    <p><?php echo $user_dashboard_stats['total_compras']; ?></p>
                </div>
                <div class="dash-card user-card">
                    <h3>Total Gasto (R$)</h3>
                    <p><?php echo number_format($user_dashboard_stats['total_gasto'], 2, ',', '.'); ?></p>
                </div>
                <div class="dash-card user-card">
                    <h3>Total Poupança (R$)</h3>
                    <p class="poupanca"><?php echo number_format($user_dashboard_stats['total_poupanca'], 2, ',', '.'); ?></p>
                </div>
            </div>
            
            <h3>Observações:</h3>
            <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 10px; border-radius: 5px; min-height: 50px;">
                <?php echo nl2br(htmlspecialchars($usuario_para_detalhes['observacoes'])); ?>
            </div>


        <?php 
        // --- VISTA PADRÃO (DASHBOARD GLOBAL + LISTA) ---
        elseif ($acao === 'dashboard'): ?>

            <h2>Dashboard Global</h2>
            <div class="dashboard">
                <div class="dash-card"><h3>Utilizadores Ativos</h3><p><?php echo $dashboard_stats['active_users']; ?> / <?php echo $dashboard_stats['total_users']; ?></p></div>
                <div class="dash-card"><h3>Utilizadores Inativos</h3><p class="inativos"><?php echo $dashboard_stats['inactive_users']; ?></p></div>
                <div class="dash-card"><h3>Compras Finalizadas</h3><p><?php echo $dashboard_stats['total_compras']; ?></p></div>
                <div class="dash-card"><h3>Total Gasto (R$)</h3><p><?php echo number_format($dashboard_stats['total_gasto'], 2, ',', '.'); ?></p></div>
                <div class="dash-card"><h3>Total Poupança (R$)</h3><p class="poupanca"><?php echo number_format($dashboard_stats['total_poupanca'], 2, ',', '.'); ?></p></div>
            </div>

            <h2>Utilizadores</h2>
            
            <div class="search-box">
                <label for="searchInput">Pesquisar Utilizador:</label>
                <input type="text" id="searchInput" onkeyup="filtrarTabela()" placeholder="Digite nome ou WhatsApp ID...">
            </div>

            <div style="overflow-x:auto;">
                <table id="userTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>WhatsApp ID</th>
                            <th>Status da Subscrição</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['nome']); ?></td>
                            <td><?php echo htmlspecialchars($user['whatsapp_id']); ?></td>
                            
                            <td>
                                <?php
                                $hoje = new DateTime();
                                $data_exp = $user['data_expiracao'] ? new DateTime($user['data_expiracao']) : null;
                                
                                if ($user['is_ativo'] && $data_exp && $data_exp >= $hoje) {
                                    echo '<span class="status-ativo">🟢 Ativo</span>';
                                    echo '<br><small>Expira em: ' . $data_exp->format('d/m/Y') . '</small>';
                                } elseif ($user['is_ativo'] && $data_exp && $data_exp < $hoje) {
                                    echo '<span class="status-expirado">🔴 Expirado</span>';
                                    echo '<br><small>Expirou em: ' . $data_exp->format('d/m/Y') . '</small>';
                                } else {
                                    echo '<span class="status-inativo">⚪ Inativo/Revogado</span>';
                                }
                                ?>
                            </td>
                            
                            <td>
                                <a href="?acao=detalhes&id=<?php echo $user['id']; ?>" class="acao-link detalhes">Detalhes</a>
                                
                                <a class="acao-link editar btn-edit"
                                   data-id="<?php echo $user['id']; ?>"
                                   data-nome="<?php echo htmlspecialchars($user['nome']); ?>"
                                   data-whatsapp="<?php echo htmlspecialchars($user['whatsapp_id']); ?>"
                                   data-observacoes="<?php echo htmlspecialchars($user['observacoes']); ?>"
                                >Editar</a>
                                
                                <a href="?add_tempo=<?php echo $user['id']; ?>&dias=30" class="acao-link add-tempo">+30d</a>
                                <a href="?add_tempo=<?php echo $user['id']; ?>&dias=90" class="acao-link add-tempo">+90d</a>
                                <a href="?add_tempo=<?php echo $user['id']; ?>&dias=365" class="acao-link add-tempo">+1 Ano</a>
                                <a href="?revogar=<?php echo $user['id']; ?>" class="acao-link revogar" onclick="return confirm('Tem a certeza que quer revogar esta subscrição?');">Revogar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>


    <?php else: ?>

        <div class="login-form">
            <h1>Acesso Restrito</h1>
            <p>Por favor, insira a senha de administrador.</p>
            <?php if ($feedback): ?>
                <p style="color: red;"><?php echo htmlspecialchars($feedback); ?></p>
            <?php endif; ?>
            <form method="POST" action="admin.php">
                <input type="password" name="senha" placeholder="Senha" required>
                <button type="submit">Entrar</button>
            </form>
        </div>

    <?php endif; ?>

    </div> <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="modal-close">&times;</span>
            <h2>Editar Utilizador</h2>
            
            <form method="POST" action="admin.php" class="form-edit">
                <input type="hidden" name="acao_form" value="salvar_usuario"> 
                <input type="hidden" name="id_usuario_modal" id="form-id">
                
                <div class="form-group">
                    <label for="form-nome">Nome:</label>
                    <input type="text" id="form-nome" name="nome_modal">
                </div>
                
                <div class="form-group">
                    <label for="form-whatsapp">WhatsApp ID (Número):</label>
                    <input type="text" id="form-whatsapp" name="whatsapp_id_modal">
                </div>

                <div class="form-group">
                    <label for="form-observacoes">Observações (Controlo de Pagamento, etc.):</label>
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