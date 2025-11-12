<?php
// ---
// /public/dashboard.php
// (VERSÃO CORRIGIDA - "Freemium" - Permite acesso a novos utilizadores)
// ---

session_start();

// 1. Segurança: Verifica se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php"); 
    exit;
}

// 2. Carrega os dados e o Model de Usuário
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\Compra;
use App\Models\HistoricoPreco; 
use App\Models\Usuario; // <-- IMPORTANTE
use DateTime;

$podeAceder = false; // <-- Variável de controlo
$alertas = []; 
$sugestoes = []; 
$stats = ['total_compras' => 0, 'total_gasto' => 0, 'total_poupado' => 0];
$comprasRecentes = [];

try {
    $pdo = getDbConnection();
    $userId = $_SESSION['user_id'];

    // 3. Carrega o OBJETO completo do utilizador
    $usuario = Usuario::findById($pdo, $userId);
    
    if (!$usuario) {
        session_destroy();
        header("Location: auth.php");
        exit;
    }
    
    $userNome = $usuario->nome ?? 'Utilizador';
    $_SESSION['user_nome'] = $userNome;

    // 4. Verifica o estado da assinatura/trial
    $expiraEm = $usuario->data_expiracao ? new DateTime($usuario->data_expiracao) : null;
    $agora = new DateTime();
    
    $isAtivo = $usuario->is_ativo && $expiraEm && $expiraEm > $agora;
    $teveTrial = $expiraEm !== null; // (Se data_expiracao não é null, ele já teve o trial)

    // --- (A CHAVE DA LÓGICA "FREEMIUM") ---
    // O utilizador pode aceder se:
    // 1) Está ativo (assinatura ou trial válidos)
    // 2) OU nunca teve um trial (é um utilizador novo)
    $podeAceder = ($isAtivo || !$teveTrial); 

    
    // 5. Carrega todos os dados estatísticos
    // (Carregamos sempre, para que o novo utilizador veja o painel, mesmo que zerado)
    $alertas = HistoricoPreco::findHighPriceAlerts($pdo, $userId, 10, 3);
    $sugestoes = HistoricoPreco::findMarketSuggestions($pdo, $userId, 60); 
    $todasCompras = Compra::findAllCompletedByUser($pdo, $userId); 
    $stats = [
        'total_compras' => count($todasCompras),
        'total_gasto' => array_sum(array_column($todasCompras, 'total_gasto')),
        'total_poupado' => array_sum(array_column($todasCompras, 'total_poupado'))
    ];
    $comprasRecentes = array_slice($todasCompras, 0, 5);
    
} catch (Exception $e) {
    $erro = "Erro ao carregar os dados: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Painel - WalletlyBot</title>
    <style>
        /* (Todo o teu CSS continua igual) */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        *, *::before, *::after { box-sizing: border-box; }
        :root {
            --cor-fundo: #1a1b26;
            --cor-fundo-card: rgba(42, 45, 62, 0.7);
            --cor-fundo-card-solido: #2a2d3e;
            --cor-texto-principal: #e0e0e0;
            --cor-texto-secundaria: #9a9bb5;
            --cor-principal: #7a5cff;
            --cor-sucesso: #00f0b5;
            --cor-alerta: #ff5c7a;
            --cor-borda: #3b3e55;
            --cor-hover: rgba(122, 92, 255, 0.15);
        }
        body { font-family: 'Inter', system-ui, sans-serif; background: radial-gradient(circle at 10% 20%, rgba(122, 92, 255, 0.1), transparent 30%), radial-gradient(circle at 90% 80%, rgba(0, 240, 181, 0.08), transparent 30%), var(--cor-fundo); margin: 0; padding: 20px; color: var(--cor-texto-principal); }
        .container { max-width: 1100px; margin: 20px auto; background: var(--cor-fundo-card); backdrop-filter: blur(10px); border: 1px solid var(--cor-borda); padding: 30px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.7); }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--cor-borda); padding-bottom: 20px; margin-bottom: 30px; }
        header h1 { color: var(--cor-principal); margin: 0; font-size: 28px; }
        header a { color: var(--cor-texto-principal); text-decoration: none; font-weight: 600; padding: 8px 15px; border: 1px solid var(--cor-borda); border-radius: 6px; transition: all 0.2s; }
        header a:hover { background: var(--cor-principal); border-color: var(--cor-principal); color: #fff; }
        h2 { color: var(--cor-texto-principal); font-weight: 600; margin: 0 0 10px 0; font-size: 24px; }
        h3 { color: var(--cor-principal); margin: 30px 0 15px 0; font-size: 18px; border-left: 4px solid var(--cor-principal); padding-left: 10px; }
        .error-box { background: rgba(255, 92, 122, 0.1); border: 1px solid var(--cor-alerta); color: var(--cor-alerta); padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        
        /* CSS para o Bloco de Ação (Trial Expirado) */
        .bloco-premium {
            background: var(--cor-fundo-card-solido);
            border: 1px solid var(--cor-principal);
            border-radius: 12px;
            padding: 30px 40px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            margin-bottom: 30px;
        }
        .bloco-premium h3 {
            color: var(--cor-principal);
            margin: 0 0 15px 0;
            font-size: 22px;
            border: none;
            padding: 0;
        }
        .bloco-premium p {
            color: var(--cor-texto-secundaria);
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 25px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        .bloco-premium a.botao-assinar {
            background: var(--cor-sucesso);
            color: var(--cor-fundo);
            font-weight: 700;
            font-size: 16px;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-block;
        }
        .bloco-premium a.botao-assinar:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(0, 240, 181, 0.4);
        }
        
        /* (O resto do teu CSS) */
        .insights-panel { display: flex; gap: 20px; margin-bottom: 30px; }
        .alerta-card, .sugestao-card { flex: 1 1 48%; background: var(--cor-fundo-card-solido); border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .alerta-card { border: 1px solid var(--cor-alerta); background: rgba(255, 92, 122, 0.1); }
        .alerta-card h4 { color: var(--cor-alerta); margin: 0 0 10px 0; font-size: 18px; font-weight: 700; }
        .alerta-card ul { margin: 0; padding-left: 20px; }
        .alerta-card li { margin-bottom: 10px; font-size: 15px; color: var(--cor-texto-principal); }
        .alerta-produto { font-weight: 600; color: var(--cor-texto-principal); }
        .sugestao-card { border: 1px solid var(--cor-sucesso); background: rgba(0, 240, 181, 0.1); }
        .sugestao-card h4 { color: var(--cor-sucesso); margin: 0 0 10px 0; font-size: 18px; font-weight: 700; }
        .sugestao-card li { margin-bottom: 10px; font-size: 15px; color: var(--cor-texto-principal); }
        .sugestao-mercado { font-weight: 600; color: var(--cor-sucesso); }
        .dashboard { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 30px; }
        .dash-card { flex: 1 1 calc(33.3% - 16px); min-width: 220px; background: var(--cor-fundo-card-solido); border: 1px solid var(--cor-borda); border-radius: 12px; padding: 20px 24px; transition: transform .2s, box-shadow .2s; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .dash-card:hover{ transform:translateY(-4px); box-shadow:0 8px 25px rgba(0,0,0,0.5); }
        .dash-card h4 { margin: 0; font-size: 14px; color: var(--cor-texto-secundaria); font-weight: 500; }
        .dash-card p { margin: 5px 0 0; font-size: 28px; font-weight: 700; }
        .dash-card p.poupado { color: var(--cor-sucesso); }
        .dash-card p.gasto { color: var(--cor-alerta); }
        .dash-card p.neutro { color: var(--cor-texto-principal); }
        .table-wrapper { overflow-x: auto; margin-top: 15px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.4); border: 1px solid var(--cor-borda); }
        table { width: 100%; min-width: 600px; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--cor-borda); color: var(--cor-texto-principal); }
        th { background-color: var(--cor-fundo-card-solido); font-size: 14px; text-transform: uppercase; color: var(--cor-principal); font-weight: 600; border-bottom: 2px solid var(--cor-principal); }
        tr:last-child td { border-bottom: none; }
        .col-valor { text-align: right; font-weight: 600; color: var(--cor-alerta); }
        .col-poupanca { text-align: right; font-weight: 600; color: var(--cor-sucesso); }
        .col-data { width: 140px; color: var(--cor-texto-secundaria); font-size: 14px; }
        .clickable-row { cursor: pointer; transition: background .2s; }
        .clickable-row:hover { background-color: var(--cor-hover); }
        .clickable-row a { text-decoration: none; color: var(--cor-texto-principal); font-weight: 600; display: block; }
        .clickable-row a:hover { color: var(--cor-principal); }
        .empty-state { background: var(--cor-fundo-card-solido); padding: 40px; text-align: center; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.5); border: 1px dashed var(--cor-borda); }
        .empty-state p { color: var(--cor-texto-secundaria); line-height: 1.6; }
        .nav-links { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 40px; padding: 15px; background: var(--cor-fundo-card-solido); border: 1px solid var(--cor-borda); border-radius: 10px; }
        .nav-links a { flex: 1 1 200px; background: rgba(122, 92, 255, 0.1); color: var(--cor-texto-principal); text-decoration: none; font-weight: 600; padding: 15px 20px; border-radius: 8px; border: 1px solid rgba(122, 92, 255, 0.5); transition: all 0.2s; text-align: center; }
        .nav-links a:hover { background: var(--cor-principal); color: #fff; border-color: var(--cor-principal); transform: translateY(-2px); box-shadow: 0 4px 10px rgba(122, 92, 255, 0.4); }
        .nav-links .export-csv { background-color: var(--cor-sucesso); border-color: var(--cor-sucesso); }
        @media (max-width: 768px) {
            body { padding: 10px; }
            .container { padding: 20px; margin: 10px auto; }
            header h1 { font-size: 24px; }
            header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .dashboard { flex-direction: column; }
            .dash-card { min-width: 100%; flex-basis: auto; }
            .insights-panel { flex-direction: column; }
            .nav-links { flex-direction: column; padding: 10px; }
            .nav-links a { flex-basis: auto; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>WalletlyBot Dashboard 📊</h1>
            <a href="logout.php">Sair</a>
        </header>
        
        <div class="content">
            <h2>Olá, <?php echo htmlspecialchars(explode(' ', $userNome)[0]); ?>!</h2>
            
            <?php if (isset($erro)): ?>
                <div class="error-box">⚠️ <?php echo $erro; ?></div>
            <?php endif; ?>

            
            <?php if ($podeAceder): ?>
                
                <?php if (!empty($alertas) || !empty($sugestoes)): ?>
                <div class="insights-panel">
                    <div class="alerta-card">
                        <h4>🚨 AVISO: Preço a Subir!</h4>
                        <?php if (empty($alertas)): ?>
                            <p style="color: var(--cor-texto-secundaria); font-weight: 500; margin: 0;">Nada subiu significativamente nos teus itens recentes. Boa notícia!</p>
                        <?php else: ?>
                            <p style="color: var(--cor-texto-secundaria); margin: 0 0 10px 0;">
                                Estes <?php echo count($alertas); ?> produtos subiram mais de 10% do melhor preço:
                            </p>
                            <ul style="list-style: disc;">
                                <?php foreach ($alertas as $alerta): ?>
                                    <li><span class="alerta-produto"><?php echo htmlspecialchars($alerta['produto_nome']); ?></span>. Poupaste R$ <?php echo number_format($alerta['diferenca'], 2, ',', '.'); ?> a menos.</li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <div class="sugestao-card">
                        <h4>🧠 Sugestões de Economia</h4>
                        <?php if (empty($sugestoes)): ?>
                            <p style="color: var(--cor-texto-secundaria); font-weight: 500; margin: 0;">Precisamos de mais dados para analisar tendências. Continua a registar!</p>
                        <?php else: ?>
                            <p style="color: var(--cor-texto-secundaria); margin: 0 0 10px 0;">O nosso sistema encontrou oportunidades nos teus itens mais comprados:</p>
                            <ul style="list-style: disc;">
                                <?php foreach ($sugestoes as $sugestao): ?>
                                    <li>Tenta comprar <span class="alerta-produto"><?php echo htmlspecialchars($sugestao['produto']); ?></span> no mercado <span class="sugestao-mercado"><?php echo htmlspecialchars($sugestao['mercado_nome']); ?></span>.</li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="dashboard">
                    <div class="dash-card">
                        <h4>Total Poupança (Lifetime)</h4>
                        <p class="poupado">R$ <?php echo number_format($stats['total_poupado'], 2, ',', '.'); ?></p>
                    </div>
                    <div class="dash-card">
                        <h4>Total Gasto (Lifetime)</h4>
                        <p class="gasto">R$ <?php echo number_format($stats['total_gasto'], 2, ',', '.'); ?></p>
                    </div>
                    <div class="dash-card">
                        <h4>Total de Compras</h4>
                        <p class="neutro"><?php echo $stats['total_compras']; ?></p>
                    </div>
                </div>

                <h3>Acesso Rápido</h3>
                <div class="nav-links">
                    <a href="historico.php">Histórico Completo 🧾</a>
                    <a href="comparador.php">Comparador de Preços 🔎</a>
                    <a href="ranking_mercados.php">Ranking de Mercados 🏆</a>
                    <a href="exportar_csv.php" class="export-csv">Exportar CSV 💾</a> 
                    <a href="configuracoes.php">Configurações ⚙️</a>
                </div>

                <h3>Últimas 5 Compras</h3>
                
                <?php if (empty($comprasRecentes)): ?>
                    <div class="empty-state">
                        <p style="font-size: 1.1em; font-weight: 500;">Ainda não tem compras finalizadas. 😕</p>
                        <p>Vá ao WhatsApp, inicie uma nova compra e registe os seus itens para ver a mágica a acontecer!</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Estabelecimento</th>
                                    <th class="col-data">Data da Compra</th>
                                    <th class="col-valor">Total Gasto</th>
                                    <th class="col-poupanca">Total Poupado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comprasRecentes as $compra): ?>
                                    <tr class="clickable-row" data-href="detalhe_compra.php?id=<?php echo $compra['id']; ?>">
                                        <td><a href="detalhe_compra.php?id=<?php echo $compra['id']; ?>"><?php echo htmlspecialchars($compra['estabelecimento_nome']); ?></a></td>
                                        <td class="col-data"><?php echo (new DateTime($compra['data_fim']))->format('d/m/Y'); ?></td>
                                        <td class="col-valor">R$ <?php echo number_format($compra['total_gasto'], 2, ',', '.'); ?></td>
                                        <td class="col-poupanca">R$ <?php echo number_format($compra['total_poupado'], 2, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="historico.php" style="color: var(--cor-principal); font-weight: 600; text-decoration: none;">Ver Histórico Completo &rarr;</a>
                    </div>
                <?php endif; ?>

                <?php else: ?>
                
                <div class="bloco-premium">
                    <h3>⏳ O teu período de teste de 24 horas terminou.</h3>
                    <p>Vimos que gostaste do bot! Para continuares a aceder ao teu painel e registar novas compras, ativa a tua assinatura mensal.</p>
                    <a href="assinar.php" class="botao-assinar">
                        Ativar Assinatura Agora
                    </a>
                </div>
                
                <h3>Últimas 5 Compras (Acesso Expirado)</h3>
                
                <div class="table-wrapper" style="filter: blur(3px); opacity: 0.5; pointer-events: none; user-select: none;">
                    <table>
                        <thead>
                            <tr>
                                <th>Estabelecimento</th>
                                <th class="col-data">Data da Compra</th>
                                <th class="col-valor">Total Gasto</th>
                                <th class="col-poupanca">Total Poupado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comprasRecentes as $compra): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($compra['estabelecimento_nome']); ?></td>
                                    <td class="col-data"><?php echo (new DateTime($compra['data_fim']))->format('d/m/Y'); ?></td>
                                    <td class="col-valor">R$ <?php echo number_format($compra['total_gasto'], 2, ',', '.'); ?></td>
                                    <td class="col-poupanca">R$ <?php echo number_format($compra['total_poupado'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php endif; ?>
            
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        <?php if ($podeAceder): // Só torna as linhas clicáveis se o user puder aceder ?>
        const rows = document.querySelectorAll("tr.clickable-row");
        rows.forEach(row => {
            row.addEventListener("click", (event) => {
                if (event.target.tagName.toLowerCase() !== 'a') {
                    window.location.href = row.dataset.href;
                }
            });
        });
        <?php endif; ?>
    });
    </script>

</body>
</html>