<?php
// --- ADMIN.PHP (v7 UI REFINADA) ---

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
<title>Walletly Admin</title>
<style>
/* === Estilos Modernos Refinados === */
:root {
  --bg:#f8fafc;--bg-card:#fff;--text:#1e293b;--accent:#3b82f6;
  --accent-light:#e0f2fe;--success:#22c55e;--danger:#ef4444;
  --warning:#f59e0b;--border:#e2e8f0;--muted:#64748b;
  font-family:"Inter",system-ui,sans-serif;
}
@media (prefers-color-scheme:dark){
  :root{--bg:#0f172a;--bg-card:#1e293b;--text:#f1f5f9;--accent:#60a5fa;
  --accent-light:#1e3a8a;--border:#334155;--muted:#94a3b8;}
}
body{background:var(--bg);color:var(--text);margin:0;}
header{background:var(--bg-card);border-bottom:1px solid var(--border);
position:sticky;top:0;z-index:50;padding:16px 30px;display:flex;align-items:center;justify-content:space-between;}
header h1{margin:0;font-size:20px;color:var(--accent);}
header a.logout{color:var(--danger);text-decoration:none;font-weight:600;}
.container{max-width:1200px;margin:30px auto;padding:0 20px;}
.feedback{margin:15px 0;padding:10px 14px;border-radius:6px;}
.feedback:not(.error){background:#dcfce7;color:#166534;}
.feedback.error{background:#fee2e2;color:#991b1b;}
.dashboard{display:flex;flex-wrap:wrap;gap:16px;margin-bottom:30px;}
.dash-card{flex:1 1 calc(33.3% - 16px);min-width:220px;background:var(--accent-light);
border-left:5px solid var(--accent);border-radius:10px;padding:16px 20px;
transition:.2s;box-shadow:0 2px 6px rgba(0,0,0,0.05);}
.dash-card:hover{transform:translateY(-3px);}
.dash-card h3{margin:0;font-size:14px;opacity:.8;}
.dash-card p{margin:5px 0 0;font-size:26px;font-weight:600;color:var(--accent);}
.search-box{margin:20px 0;position:relative;}
.search-box input{width:100%;max-width:340px;padding:10px 10px 10px 36px;
border:1px solid var(--border);border-radius:8px;}
.search-box::before{content:"🔍";position:absolute;left:12px;top:9px;font-size:16px;opacity:.6;}
table{width:100%;border-collapse:collapse;border:1px solid var(--border);
border-radius:8px;overflow:hidden;font-size:14px;}
th,td{padding:12px;border-bottom:1px solid var(--border);}
th{background:var(--accent-light);text-align:left;}
tr:nth-child(even){background:rgba(0,0,0,0.02);}
tr:hover{background:var(--accent-light);}
.acao-link{padding:6px 10px;border-radius:6px;font-weight:500;text-decoration:none;transition:background .2s;margin:2px;display:inline-block;}
.editar{background:var(--accent);color:#fff;}
.editar:hover{background:#2563eb;}
.detalhes{background:var(--warning);color:#fff;}
.revogar{background:var(--danger);color:#fff;}
.status-ativo{color:var(--success);font-weight:bold;}
.status-expirado{color:var(--danger);font-weight:bold;}
.status-inativo{color:var(--muted);}
.acoes-tempo{margin-top:8px;font-size:13px;}
.acoes-tempo a{display:inline-block;margin:0 2px;padding:4px 8px;
border-radius:999px;background:var(--accent-light);color:var(--accent);
border:1px solid var(--border);transition:.2s;}
.acoes-tempo a:hover{background:var(--accent);color:#fff;}
.modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;
backdrop-filter:blur(4px);background:rgba(0,0,0,0.3);}
.modal-content{background:var(--bg-card);margin:10% auto;padding:25px;border-radius:10px;
width:90%;max-width:500px;box-shadow:0 6px 20px rgba(0,0,0,0.2);}
.modal-close{float:right;font-size:22px;cursor:pointer;color:var(--danger);}
.btn{padding:10px 15px;border:none;border-radius:6px;cursor:pointer;color:#fff;}
.btn-primary{background:var(--accent);}
.login-form{max-width:400px;margin:120px auto;background:var(--bg-card);
border-radius:10px;padding:30px;text-align:center;border:1px solid var(--border);}
</style>
</head>
<body>

<header>
  <h1>Walletly Admin</h1>
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
<div class="dash-card"><h3><?php echo htmlspecialchars($usuario_para_detalhes['nome']); ?></h3>
<p>ID <?php echo $usuario_para_detalhes['id']; ?><br>
WhatsApp: <?php echo htmlspecialchars($usuario_para_detalhes['whatsapp_id']); ?><br>
Desde: <?php echo (new DateTime($usuario_para_detalhes['criado_em']))->format('d/m/Y'); ?><br>
Obs: <?php echo htmlspecialchars($usuario_para_detalhes['observacoes']); ?></p></div>
<div class="dashboard">
<div class="dash-card"><h3>Compras</h3><p><?php echo $user_dashboard_stats['total_compras']; ?></p></div>
<div class="dash-card"><h3>Valor Gasto</h3><p>R$ <?php echo number_format($user_dashboard_stats['total_gasto'],2,',','.'); ?></p></div>
<div class="dash-card"><h3>Poupança</h3><p>R$ <?php echo number_format($user_dashboard_stats['total_poupanca'],2,',','.'); ?></p></div>
</div>

<?php else: ?>
<h2>Visão Geral</h2>
<div class="dashboard">
<div class="dash-card"><h3>Total Utilizadores</h3><p><?php echo $dashboard_stats['total_users']; ?></p></div>
<div class="dash-card"><h3>Ativos</h3><p><?php echo $dashboard_stats['active_users']; ?></p></div>
<div class="dash-card"><h3>Inativos</h3><p><?php echo $dashboard_stats['inactive_users']; ?></p></div>
<div class="dash-card"><h3>Total Compras</h3><p><?php echo $dashboard_stats['total_compras']; ?></p></div>
<div class="dash-card"><h3>Valor Transacionado</h3><p>R$ <?php echo number_format($dashboard_stats['total_gasto'],2,',','.'); ?></p></div>
<div class="dash-card"><h3>Poupança Global</h3><p>R$ <?php echo number_format($dashboard_stats['total_poupanca'],2,',','.'); ?></p></div>
</div>

<h2>Utilizadores</h2>
<div class="search-box">
<input type="text" id="searchInput" onkeyup="filtrarTabela()" placeholder="Pesquisar por nome ou WhatsApp...">
</div>
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
<a href="?revogar=<?php echo $u['id']; ?>" class="acao-link revogar" onclick="return confirm('Revogar subscrição?');">Revogar</a>
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
<?php endif; ?>

<?php else: ?>
<form action="admin.php" method="POST" class="login-form">
<h2>Login Admin</h2>
<?php if($feedback): ?><div class="feedback error"><?php echo htmlspecialchars($feedback); ?></div><?php endif; ?>
<input type="password" name="senha" placeholder="Senha" required style="width:100%;padding:10px;margin:10px 0;border:1px solid var(--border);border-radius:6px;">
<button type="submit" class="btn btn-primary">Entrar</button>
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
<label>Nome</label><input type="text" id="form-nome" name="nome_modal" style="width:100%;padding:8px;"><br>
<label>WhatsApp</label><input type="text" id="form-whatsapp" name="whatsapp_id_modal" style="width:100%;padding:8px;"><br>
<label>Observações</label><textarea id="form-observacoes" name="observacoes_modal" style="width:100%;padding:8px;"></textarea><br>
<button type="submit" class="btn btn-primary">Salvar</button>
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
