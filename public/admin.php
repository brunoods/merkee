<?php
// --- ADMIN.PHP (v8 DARK THEME & RESPONSIVO) ---

require_once __DIR__ . '/../config/bootstrap.php';
session_start();

// --- LOGIN / LOGOUT ---
function login() {
    if (!empty($_POST['senha'])) {
        $hashCorreto = $_ENV['ADMIN_PASSWORD_HASH'] ?? getenv('ADMIN_PASSWORD_HASH');
        if (empty($hashCorreto)) return "Erro: ADMIN_PASSWORD_HASH não definido.";
        if (password_verify($_POST['senha'], $hashCorreto)) {
            $_SESSION['admin_logado'] = true;
            header("Location: admin.php"); exit;
        } else return "Senha incorreta!";
    }
    return null;
}
function logout() {
    unset($_SESSION['admin_logado']);
    header("Location: admin.php"); exit;
}

// --- VARIÁVEIS ---
$feedback = null;
$acao = $_GET['acao'] ?? 'dashboard';
$dashboard_stats = [];
$usuarios = [];
$usuario_para_detalhes = null;
$user_dashboard_stats = [];

try {
    if (isset($_SESSION['admin_logado']) && $_SESSION['admin_logado'] === true) {
        $pdo = getDbConnection();

        // Atualizar usuário
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao_form'] ?? '') === 'salvar_usuario') {
            $id = (int)$_POST['id_usuario_modal'];
            $nome = trim($_POST['nome_modal']);
            $whatsapp_id = trim($_POST['whatsapp_id_modal']);
            $observacoes = trim($_POST['observacoes_modal']);
            $stmt = $pdo->prepare("UPDATE usuarios SET nome=?, whatsapp_id=?, observacoes=? WHERE id=?");
            $stmt->execute([$nome, $whatsapp_id, $observacoes, $id]);
            $feedback = "Utilizador #{$id} atualizado com sucesso!";
            $acao = 'dashboard';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if ($acao === 'logout') logout();

            if (isset($_GET['add_tempo'])) {
                $id = (int)$_GET['add_tempo'];
                $dias = (int)$_GET['dias'];
                $stmt_data = $pdo->prepare("SELECT data_expiracao FROM usuarios WHERE id=?");
                $stmt_data->execute([$id]);
                $data_atual_str = $stmt_data->fetchColumn();
                $data_base = new DateTime();
                $data_atual = $data_atual_str ? new DateTime($data_atual_str) : null;
                if ($data_atual && $data_atual > $data_base) $data_base = $data_atual;
                $data_base->add(new DateInterval("P{$dias}D"));
                $nova_data_expiracao = $data_base->format('Y-m-d H:i:s');
                $pdo->prepare("UPDATE usuarios SET is_ativo=1, data_expiracao=? WHERE id=?")->execute([$nova_data_expiracao, $id]);
                $feedback = "Subscrição do Utilizador #{$id} estendida até " . $data_base->format('d/m/Y');
                $acao = 'dashboard';
            }

            if (isset($_GET['revogar'])) {
                $id = (int)$_GET['revogar'];
                $pdo->prepare("UPDATE usuarios SET is_ativo=0, data_expiracao=NULL WHERE id=?")->execute([$id]);
                $feedback = "Subscrição do Utilizador #{$id} revogada com sucesso.";
                $acao = 'dashboard';
            }
        }

        if ($acao === 'detalhes') {
            $id_usuario = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id=?");
            $stmt->execute([$id_usuario]);
            $usuario_para_detalhes = $stmt->fetch();

            $stmt = $pdo->prepare("SELECT COUNT(*) total_compras, SUM(total_gasto) total_valor FROM compras WHERE usuario_id=? AND status='finalizada'");
            $stmt->execute([$id_usuario]);
            $stats_compras = $stmt->fetch();
            $user_dashboard_stats['total_compras'] = $stats_compras['total_compras'] ?? 0;
            $user_dashboard_stats['total_gasto'] = $stats_compras['total_valor'] ?? 0;

            $stmt = $pdo->prepare("
                SELECT SUM((i.preco_normal - i.preco) * i.quantidade) total_poupanca
                FROM itens_compra i
                JOIN compras c ON i.compra_id=c.id
                WHERE c.usuario_id=? AND i.em_promocao=1 AND i.preco_normal>preco
            ");
            $stmt->execute([$id_usuario]);
            $user_dashboard_stats['total_poupanca'] = $stmt->fetchColumn() ?? 0;
        } else {
            $stats = $pdo->query("
                SELECT COUNT(*) total,
                SUM(CASE WHEN is_ativo=1 AND (data_expiracao IS NULL OR data_expiracao>=NOW()) THEN 1 ELSE 0 END) ativos
                FROM usuarios
            ")->fetch();
            $dashboard_stats['total_users'] = $stats['total'] ?? 0;
            $dashboard_stats['active_users'] = $stats['ativos'] ?? 0;
            $dashboard_stats['inactive_users'] = $dashboard_stats['total_users'] - $dashboard_stats['active_users'];
            $stats_compras = $pdo->query("SELECT COUNT(*) total_compras, SUM(total_gasto) total_valor FROM compras WHERE status='finalizada'")->fetch();
            $dashboard_stats['total_compras'] = $stats_compras['total_compras'] ?? 0;
            $dashboard_stats['total_gasto'] = $stats_compras['total_valor'] ?? 0;
            $stats_poupanca = $pdo->query("SELECT SUM((preco_normal - preco) * quantidade) total_poupanca FROM itens_compra WHERE em_promocao=1 AND preco_normal>preco")->fetch();
            $dashboard_stats['total_poupanca'] = $stats_poupanca['total_poupanca'] ?? 0;
            $usuarios = $pdo->query("SELECT id, nome, whatsapp_id, is_ativo, criado_em, observacoes, data_expiracao FROM usuarios ORDER BY criado_em DESC")->fetchAll();
        }
    } else $feedback = login();
} catch (Exception $e) { $feedback = "ERRO: " . $e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>WalletlyBot Admin</title>
<style>
/* === Dark Theme & Responsivo UI/UX Nível 10 === */
:root {
  --cor-fundo: #121212;
  --cor-fundo-card: #1f1f1f;
  --cor-texto-principal: #f0f0f0;
  --cor-texto-secundaria: #a0a0a0;
  --cor-principal: #0a9396; /* Azul Água (Accent) */
  --cor-sucesso: #90ee90; 
  --cor-alerta: #ff6b6b; 
  --cor-borda: #444444;
  --cor-hover: #3c3c3c;
  --cor-feedback-success-bg: #1e3a2d;
  --cor-feedback-error-bg: #442222;
}
body{background:var(--cor-fundo);color:var(--cor-texto-principal);margin:0;font-family:"Inter",system-ui,sans-serif;}
a{text-decoration:none;color:var(--cor-principal);}
h2{color:var(--cor-texto-principal);font-weight:600;font-size:22px;margin:20px 0 15px 0;border-left:4px solid var(--cor-principal);padding-left:10px;}
header{background:var(--cor-fundo-card);border-bottom:1px solid var(--cor-borda);
position:sticky;top:0;z-index:50;padding:16px 30px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,0.5);}
header h1{margin:0;font-size:24px;color:var(--cor-principal);}
header a.logout{color:var(--cor-alerta);text-decoration:none;font-weight:600;padding:5px 10px;border-radius:6px;transition:.2s;}
header a.logout:hover{background:var(--cor-alerta);color:var(--cor-fundo-card);}

.container{max-width:1200px;margin:30px auto;padding:0 20px;}
.feedback{margin:15px 0;padding:12px 16px;border-radius:6px;font-weight:500;}
.feedback:not(.error){background:var(--cor-feedback-success-bg);color:var(--cor-sucesso);border:1px solid var(--cor-sucesso);}
.feedback.error{background:var(--cor-feedback-error-bg);color:var(--cor-alerta);border:1px solid var(--cor-alerta);}

/* DASHBOARD CARDS */
.dashboard{display:flex;flex-wrap:wrap;gap:16px;margin-bottom:30px;}
.dash-card{flex:1 1 calc(33.3% - 16px);min-width:220px;background:#252525;
border-left:5px solid var(--cor-principal);border-radius:10px;padding:16px 20px;
transition:.2s;box-shadow:0 4px 8px rgba(0,0,0,0.5);}
.dash-card:hover{transform:translateY(-3px);box-shadow:0 6px 12px rgba(0,0,0,0.7);}
.dash-card h3{margin:0;font-size:14px;color:var(--cor-texto-secundaria);}
.dash-card p{margin:5px 0 0;font-size:28px;font-weight:700;color:var(--cor-principal);}

/* SEARCH BOX */
.search-box{margin:20px 0;position:relative;}
.search-box input{width:100%;max-width:400px;padding:10px 10px 10px 36px;
border:1px solid var(--cor-borda);border-radius:8px;background:var(--cor-fundo-card);color:var(--cor-texto-principal);transition:.2s;}
.search-box input::placeholder{color:var(--cor-texto-secundaria);}
.search-box::before{content:"🔍";position:absolute;left:12px;top:11px;font-size:16px;opacity:.8;color:var(--cor-texto-secundaria);}
.search-box input:focus{border-color:var(--cor-principal);box-shadow:0 0 0 2px rgba(10, 147, 150, 0.3);}

/* TABELA */
.table-wrapper{overflow-x:auto;margin-top:15px;border-radius:8px;box-shadow:0 4px 8px rgba(0,0,0,0.5);}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:700px;}
th,td{padding:12px;border-bottom:1px solid var(--cor-borda);text-align:left;}
th{background:#252525;color:var(--cor-principal);font-weight:600;border-bottom:2px solid var(--cor-principal);}
tr:nth-child(even){background:#222222;}
tr:hover{background:var(--cor-hover);}

/* AÇÕES */
.acao-link{padding:6px 10px;border-radius:6px;font-weight:500;text-decoration:none;transition:background .2s;margin:2px;display:inline-block;font-size:13px;}
.editar{background:var(--cor-principal);color:var(--cor-fundo-card);border:1px solid var(--cor-principal);}
.editar:hover{background:#077e81;}
.detalhes{background:#3b3b3b;color:var(--cor-texto-principal);border:1px solid #555;}
.detalhes:hover{background:#555;}
.revogar{background:var(--cor-alerta);color:var(--cor-fundo-card);border:1px solid var(--cor-alerta);}
.revogar:hover{background:#d83c3c;}

.status-ativo{color:var(--cor-sucesso);font-weight:bold;}
.status-expirado{color:var(--cor-alerta);font-weight:bold;}
.status-inativo{color:var(--cor-texto-secundaria);}

.acoes-tempo{margin-top:8px;font-size:13px;display:flex;align-items:center;gap:5px;}
.acoes-tempo span{color:var(--cor-texto-secundaria);}
.acoes-tempo a{display:inline-block;padding:4px 8px;border-radius:999px;
background:var(--cor-fundo-card);color:var(--cor-principal);border:1px solid var(--cor-borda);transition:.2s;}
.acoes-tempo a:hover{background:var(--cor-principal);color:var(--cor-fundo-card);border-color:var(--cor-principal);}

/* MODAL */
.modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;
backdrop-filter:blur(4px);background:rgba(0,0,0,0.7);}
.modal-content{background:var(--cor-fundo-card);margin:10% auto;padding:25px;border-radius:10px;
width:90%;max-width:500px;box-shadow:0 8px 30px rgba(0,0,0,0.9);border-top:4px solid var(--cor-principal);}
.modal-close{float:right;font-size:28px;cursor:pointer;color:var(--cor-alerta);transition:.2s;}
.modal-close:hover{color:var(--cor-principal);}

.modal-content input, .modal-content textarea{
    background: #252525; border: 1px solid var(--cor-borda); color: var(--cor-texto-principal); margin-bottom: 10px; border-radius: 4px; padding: 8px;
}
.modal-content label{display:block;margin-top:10px;font-size:14px;color:var(--cor-texto-secundaria);}
.btn-primary{background:var(--cor-principal);color:var(--cor-fundo-card);padding:10px 15px;border:none;border-radius:6px;cursor:pointer;font-weight:600;margin-top:10px;}
.btn-primary:hover{background:#077e81;}

/* LOGIN FORM */
.login-form{max-width:400px;margin:120px auto;background:var(--cor-fundo-card);
border-radius:10px;padding:30px;text-align:center;border:1px solid var(--cor-borda);box-shadow:0 4px 15px rgba(0,0,0,0.7);}
.login-form input{background:#252525;color:var(--cor-texto-principal);}

/* RESPONSIVIDADE */
@media (max-width: 768px) {
    header { padding: 10px 15px; }
    header h1 { font-size: 20px; }
    .container { padding: 0 10px; margin-top: 20px; }
    .dashboard { flex-direction: column; }
    .dash-card { min-width: 100%; }
    h2 { font-size: 20px; }
    .modal-content { margin: 20% auto; }
}
</style>
</head>
<body>

<header>
  <h1>WalletlyBot Admin</h1>
  <?php if(isset($_SESSION['admin_logado']) && $_SESSION['admin_logado']===true): ?>
  <a href="?acao=logout" class="logout">Sair</a>
  <?php endif; ?>
</header>

<div class="container">
<?php if(isset($_SESSION['admin_logado'])&&$_SESSION['admin_logado']===true): ?>

<?php if($feedback): ?>
<div class="feedback <?php echo str_starts_with($feedback,'ERRO')?'error':''; ?>"><?php echo htmlspecialchars($feedback); ?></div>
<?php endif; ?>

<?php if($acao==='detalhes'&&$usuario_para_detalhes): ?>
<h2><a href="?acao=dashboard" class="acao-link detalhes">&larr; Voltar</a> Detalhes do Utilizador</h2>
<div class="dash-card">
    <h3>Informações do Utilizador</h3>
    <p style="font-size:16px;">
        Nome: <?php echo htmlspecialchars($usuario_para_detalhes['nome']); ?><br>
        ID: <?php echo $usuario_para_detalhes['id']; ?><br>
        WhatsApp: <?php echo htmlspecialchars($usuario_para_detalhes['whatsapp_id']); ?><br>
        Desde: <?php echo (new DateTime($usuario_para_detalhes['criado_em']))->format('d/m/Y'); ?><br>
        Observações: <?php echo htmlspecialchars($usuario_para_detalhes['observacoes']); ?>
    </p>
</div>
<h2 style="margin-top:30px;">Estatísticas de Atividade</h2>
<div class="dashboard">
    <div class="dash-card"><h3>Compras Registadas</h3><p><?php echo $user_dashboard_stats['total_compras']; ?></p></div>
    <div class="dash-card"><h3>Valor Gasto</h3><p>R$ <?php echo number_format($user_dashboard_stats['total_gasto'],2,',','.'); ?></p></div>
    <div class="dash-card"><h3>Poupança Total</h3><p>R$ <?php echo number_format($user_dashboard_stats['total_poupanca'],2,',','.'); ?></p></div>
</div>

<?php else: ?>
<h2>Visão Geral do Sistema</h2>
<div class="dashboard">
<div class="dash-card"><h3>Total Utilizadores</h3><p><?php echo $dashboard_stats['total_users']; ?></p></div>
<div class="dash-card"><h3>Ativos</h3><p><?php echo $dashboard_stats['active_users']; ?></p></div>
<div class="dash-card"><h3>Inativos</h3><p><?php echo $dashboard_stats['inactive_users']; ?></p></div>
<div class="dash-card"><h3>Total Compras</h3><p><?php echo $dashboard_stats['total_compras']; ?></p></div>
<div class="dash-card"><h3>Valor Transacionado</h3><p>R$ <?php echo number_format($dashboard_stats['total_gasto'],2,',','.'); ?></p></div>
<div class="dash-card"><h3>Poupança Global</h3><p>R$ <?php echo number_format($dashboard_stats['total_poupanca'],2,',','.'); ?></p></div>
</div>

<h2>Gestão de Utilizadores</h2>
<div class="search-box">
<input type="text" id="searchInput" onkeyup="filtrarTabela()" placeholder="Pesquisar por nome ou WhatsApp...">
</div>
<div class="table-wrapper">
<table id="userTable">
<thead><tr><th>ID</th><th>Nome</th><th>WhatsApp</th><th>Status</th><th>Desde</th><th>Obs</th><th>Ações</th></tr></thead>
<tbody>
<?php foreach($usuarios as $u): 
$data_exp = $u['data_expiracao']?new DateTime($u['data_expiracao']):null;
if($u['is_ativo']&&$data_exp&&$data_exp>=new DateTime()){ $status='Ativo (expira '.$data_exp->format('d/m/Y').')'; $cls='status-ativo'; }
elseif($u['is_ativo']&&$data_exp&&$data_exp<new DateTime()){ $status='Expirado ('.$data_exp->format('d/m/Y').')'; $cls='status-expirado'; }
else{ $status='Inativo'; $cls='status-inativo'; }
?>
<tr>
<td><?php echo $u['id']; ?></td>
<td><?php echo htmlspecialchars($u['nome']); ?></td>
<td><?php echo htmlspecialchars($u['whatsapp_id']); ?></td>
<td><span class="<?php echo $cls; ?>"><?php echo $status; ?></span></td>
<td><?php echo (new DateTime($u['criado_em']))->format('d/m/Y'); ?></td>
<td><?php echo htmlspecialchars($u['observacoes']); ?></td>
<td>
<span class="acao-link editar btn-edit" data-id="<?php echo $u['id']; ?>" data-nome="<?php echo htmlspecialchars($u['nome']); ?>" data-whatsapp="<?php echo htmlspecialchars($u['whatsapp_id']); ?>" data-observacoes="<?php echo htmlspecialchars($u['observacoes']); ?>">Editar</span>
<a href="?acao=detalhes&id=<?php echo $u['id']; ?>" class="acao-link detalhes">Detalhes</a>
<a href="?revogar=<?php echo $u['id']; ?>" class="acao-link revogar" onclick="return confirm('Tem certeza que deseja revogar a subscrição deste utilizador?');">Revogar</a>
<div class="acoes-tempo">
<span>Extender:</span>
<a href="?add_tempo=<?php echo $u['id']; ?>&dias=7" onclick="return confirm('Adicionar 7 dias?');">+7d</a>
<a href="?add_tempo=<?php echo $u['id']; ?>&dias=30" onclick="return confirm('Adicionar 30 dias?');">+30d</a>
<a href="?add_tempo=<?php echo $u['id']; ?>&dias=90" onclick="return confirm('Adicionar 90 dias?');">+90d</a>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php else: ?>
<form action="admin.php" method="POST" class="login-form">
<h2>Login Admin</h2>
<?php if($feedback): ?><div class="feedback error"><?php echo htmlspecialchars($feedback); ?></div><?php endif; ?>
<input type="password" name="senha" placeholder="Senha" required style="width:100%;padding:10px;margin:10px 0;border:1px solid var(--cor-borda);border-radius:6px;background:#252525;color:var(--cor-texto-principal);">
<button type="submit" class="btn btn-primary btn-full-width" style="width:100%;" >Entrar</button>
</form>
<?php endif; ?>
</div>

<div id="editModal" class="modal">
<div class="modal-content">
<span class="modal-close">&times;</span>
<h2>Editar Utilizador</h2>
<form action="admin.php?acao=dashboard" method="POST">
<input type="hidden" name="acao_form" value="salvar_usuario">
<input type="hidden" name="id_usuario_modal" id="form-id">
<label>Nome</label><input type="text" id="form-nome" name="nome_modal" style="width:100%;"><br>
<label>WhatsApp ID</label><input type="text" id="form-whatsapp" name="whatsapp_id_modal" style="width:100%;"><br>
<label>Observações</label><textarea id="form-observacoes" name="observacoes_modal" style="width:100%;height:80px;"></textarea><br>
<button type="submit" class="btn btn-primary" style="width:100%;">Salvar</button>
</form>
</div></div>

<script>
document.addEventListener("DOMContentLoaded",function(){
var modal=document.getElementById("editModal");
var spanClose=document.getElementsByClassName("modal-close")[0];
document.querySelectorAll(".btn-edit").forEach(btn=>{
btn.onclick=function(){
document.getElementById('form-id').value=this.dataset.id;
document.getElementById('form-nome').value=this.dataset.nome;
document.getElementById('form-whatsapp').value=this.dataset.whatsapp;
document.getElementById('form-observacoes').value=this.dataset.observacoes;
modal.style.display="block";
}
});
spanClose.onclick=()=>modal.style.display="none";
window.onclick=e=>{if(e.target==modal)modal.style.display="none";}
});
function filtrarTabela(){
let input=document.getElementById("searchInput").value.toLowerCase();
document.querySelectorAll("#userTable tbody tr").forEach(tr=>{
let nome=tr.children[1].textContent.toLowerCase();
let whats=tr.children[2].textContent.toLowerCase();
tr.style.display=(nome.includes(input)||whats.includes(input))?"":"none";
});
}
</script>
</body>
</html>