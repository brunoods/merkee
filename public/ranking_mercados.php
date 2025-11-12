<?php
// ---
// /public/ranking_mercados.php
// (Funcionalidade #12: Ranking de Mercados)
// ---

session_start();

// 1. Segurança: Verifica se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php"); 
    exit;
}

// 2. Carrega as dependências
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\Compra;

$erro = null;
$ranking = [];
$userId = (int)$_SESSION['user_id'];
$userNome = $_SESSION['user_nome'];

try {
    $pdo = getDbConnection();
    
    // 3. FEATURE #12: Chama o novo método do Modelo Compra
    $ranking = Compra::findMarketRanking($pdo, $userId);
    
} catch (Exception $e) {
    $erro = "Erro ao carregar o ranking: " . $e->getMessage();
}

$nomeCurto = htmlspecialchars(explode(' ', $userNome)[0]);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking de Mercados - WalletlyBot</title>
    <style>
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
        
        /* HEADER */
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--cor-borda); padding-bottom: 20px; margin-bottom: 30px; }
        header h1 { color: var(--cor-principal); margin: 0; font-size: 28px; }
        header a { color: var(--cor-texto-principal); text-decoration: none; font-weight: 600; padding: 8px 15px; border: 1px solid var(--cor-borda); border-radius: 6px; transition: all 0.2s; }
        header a:hover { background: var(--cor-principal); border-color: var(--cor-principal); color: #fff; }

        h2 { color: var(--cor-texto-principal); font-weight: 600; margin: 0 0 10px 0; font-size: 24px; }
        h3 { color: var(--cor-principal); margin: 30px 0 15px 0; font-size: 18px; border-left: 4px solid var(--cor-principal); padding-left: 10px; }
        .error-box { background: rgba(255, 92, 122, 0.1); border: 1px solid var(--cor-alerta); color: var(--cor-alerta); padding: 15px; border-radius: 8px; margin-bottom: 20px; }

        /* TABELA (Ranking) */
        .table-wrapper { overflow-x: auto; margin-top: 15px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.4); border: 1px solid var(--cor-borda); }
        table { width: 100%; min-width: 700px; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--cor-borda); color: var(--cor-texto-principal); }
        th { background-color: var(--cor-fundo-card-solido); font-size: 14px; text-transform: uppercase; color: var(--cor-principal); font-weight: 600; border-bottom: 2px solid var(--cor-principal); }
        tr:last-child td { border-bottom: none; }
        
        .col-valor, .col-porcentagem, .col-compras { text-align: right; font-weight: 600; }
        .col-poupanca { color: var(--cor-sucesso); }
        .col-gasto { color: var(--cor-alerta); }
        .col-rank { width: 50px; font-weight: 700; font-size: 1.1em; color: var(--cor-principal); }
        
        .empty-state { background: var(--cor-fundo-card-solido); padding: 40px; text-align: center; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.5); border: 1px dashed var(--cor-borda); }

        /* RESPONSIVIDADE */
        @media (max-width: 768px) {
            body { padding: 10px; }
            .container { padding: 20px; margin: 10px auto; }
            header h1 { font-size: 24px; }
            header { flex-direction: column; align-items: flex-start; gap: 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Ranking de Mercados 🏆</h1>
            <a href="dashboard.php">← Voltar ao Dashboard</a>
        </header>
        
        <div class="content">
            <h2>Qual é o mercado mais vantajoso, <?php echo $nomeCurto; ?>?</h2>
            
            <?php if (isset($erro)): ?>
                <div class="error-box">⚠️ <?php echo $erro; ?></div>
            <?php endif; ?>

            <p style="color: var(--cor-texto-secundaria);">
                Esta tabela mostra onde as tuas compras resultaram na maior poupança total.
            </p>
            
            <?php if (empty($ranking)): ?>
                
                <div class="empty-state">
                    <p style="font-size: 1.1em; font-weight: 500;">Precisamos de mais dados para gerar o teu ranking.</p>
                    <p>Regista as tuas compras no WhatsApp. Quanto mais itens com preço, melhor fica esta análise!</p>
                </div>

            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-rank">#</th>
                                <th>Estabelecimento</th>
                                <th class="col-compras">Compras</th>
                                <th class="col-valor col-gasto">Gasto Total</th>
                                <th class="col-valor col-poupanca">Poupança Total</th>
                                <th class="col-porcentagem">Poupança %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($ranking as $mercado): 
                                // O campo percentual_poupado pode ser NULL se gasto_total for 0
                                $percentual = $mercado['percentual_poupado'] ?? 0;
                            ?>
                                <tr>
                                    <td class="col-rank"><?php echo $rank++; ?></td>
                                    <td><?php echo htmlspecialchars($mercado['estabelecimento_nome']); ?></td>
                                    <td class="col-compras"><?php echo (int)$mercado['total_compras']; ?></td>
                                    <td class="col-valor col-gasto">R$ <?php echo number_format($mercado['gasto_total'], 2, ',', '.'); ?></td>
                                    <td class="col-valor col-poupanca">R$ <?php echo number_format($mercado['poupado_total'], 2, ',', '.'); ?></td>
                                    <td class="col-porcentagem col-poupanca"><?php echo number_format($percentual, 1, ',', '.'); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>