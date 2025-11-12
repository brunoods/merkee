<?php
// ---
// /public/detalhe_compra.php
// (Funcionalidade #10: Detalhe de Compra - v9 Aurora Glass)
// ---

session_start();

// 1. Segurança: Verifica se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php"); 
    exit;
}

// 2. Carrega tudo
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\Compra;

$erro = null;
$compra = null;
$itens = [];

$compraId = (int)($_GET['id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if ($compraId === 0) {
    $erro = "Erro: Nenhum ID de compra fornecido.";
} else {
    try {
        $pdo = getDbConnection();
        
        // 3. Busca os dados da compra (e verifica se pertence ao utilizador)
        $compra = Compra::findByIdAndUser($pdo, $compraId, $userId);
        
        if (!$compra) {
            $erro = "Compra não encontrada ou não pertence a si.";
        } else {
            // 4. Se a compra é válida, busca os itens dela
            $itens = Compra::findItemsByCompraId($pdo, $compraId);
        }
        
    } catch (Exception $e) {
        $erro = "Erro ao carregar os dados: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhe da Compra - WalletlyBot</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        /* Paleta de Cores v9 Aurora Glass */
        :root {
            --cor-fundo: #1a1b26;
            --cor-fundo-card: rgba(42, 45, 62, 0.7); /* Glassmorphism */
            --cor-fundo-card-solido: #2a2d3e;
            --cor-texto-principal: #e0e0e0;
            --cor-texto-secundaria: #9a9bb5;
            --cor-principal: #7a5cff; /* Roxo/Violeta */
            --cor-sucesso: #00f0b5; /* Verde Menta */
            --cor-alerta: #ff5c7a; /* Vermelho/Rosa */
            --cor-borda: #3b3e55;
            --cor-hover: rgba(122, 92, 255, 0.15); /* Roxo translúcido */
        }

        body { 
            font-family: 'Inter', system-ui, sans-serif; 
            background: radial-gradient(circle at 10% 20%, rgba(122, 92, 255, 0.1), transparent 30%),
                        radial-gradient(circle at 90% 80%, rgba(0, 240, 181, 0.08), transparent 30%),
                        var(--cor-fundo); 
            margin: 0; 
            padding: 20px; 
            color: var(--cor-texto-principal); 
        }
        .container { 
            max-width: 1100px; 
            margin: 20px auto; 
            background: var(--cor-fundo-card);
            backdrop-filter: blur(10px);
            border: 1px solid var(--cor-borda);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.7); 
        }

        /* HEADER */
        header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 2px solid var(--cor-borda); 
            padding-bottom: 20px; 
            margin-bottom: 30px;
        }
        header h1 { 
            color: var(--cor-principal); 
            margin: 0; 
            font-size: 28px; 
        }
        header a { 
            color: var(--cor-texto-principal); 
            text-decoration: none; 
            font-weight: 600; 
            padding: 8px 15px;
            border: 1px solid var(--cor-borda);
            border-radius: 6px;
            transition: all 0.2s;
        }
        header a:hover {
            background: var(--cor-principal);
            border-color: var(--cor-principal);
            color: #fff;
        }
        
        /* BOTÃO VOLTAR */
        .link-voltar {
            color: var(--cor-principal);
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 25px;
            transition: color 0.2s;
            padding: 5px 10px;
            border-radius: 6px;
        }
        .link-voltar:hover {
            color: var(--cor-sucesso);
            background: rgba(0, 240, 181, 0.1);
        }

        /* CABEÇALHO DA COMPRA (CARD DE DESTAQUE) */
        .compra-header { 
            background: var(--cor-fundo-card-solido); 
            padding: 25px; 
            border-radius: 10px; 
            border-left: 5px solid var(--cor-principal);
            margin-bottom: 30px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.5);
        }
        .compra-header h2 { 
            margin: 0 0 5px 0; 
            color: var(--cor-texto-principal); 
            font-size: 24px;
        }
        .compra-header p { 
            margin: 0 0 15px 0; 
            font-size: 15px; 
            color: var(--cor-texto-secundaria); 
        }
        .compra-header .totais { 
            display: flex; 
            gap: 40px; 
            margin-top: 20px; 
        }
        .compra-header .totais div { 
            font-size: 15px; 
            color: var(--cor-texto-secundaria);
        }
        .compra-header .totais span { 
            display: block; 
            font-size: 24px; 
            font-weight: bold; 
            margin-top: 5px;
        }
        .totais .gasto { color: var(--cor-alerta); }
        .totais .poupado { color: var(--cor-sucesso); }
        
        /* TÍTULO DOS ITENS */
        h3 { color: var(--cor-texto-principal); margin: 30px 0 15px 0; font-size: 18px; border-left: 4px solid var(--cor-principal); padding-left: 10px; }
        
        /* TABELA DE ITENS */
        .table-wrapper {
            overflow-x: auto;
            margin-top: 15px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.4); 
            border: 1px solid var(--cor-borda);
        }
        table { 
            width: 100%; 
            min-width: 700px;
            border-collapse: collapse; 
        }
        th, td { 
            padding: 12px 15px; 
            text-align: left; 
            border-bottom: 1px solid var(--cor-borda); 
        }
        th { 
            background-color: var(--cor-fundo-card-solido); 
            font-size: 14px; 
            text-transform: uppercase;
            color: var(--cor-principal); 
            font-weight: 600; 
            border-bottom: 2px solid var(--cor-principal);
        }
        td { font-size: 15px; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background-color: var(--cor-hover); }

        .col-valor { text-align: right; font-weight: 600; color: var(--cor-alerta); }
        .col-poupanca { text-align: right; font-weight: 600; color: var(--cor-sucesso); }
        .col-preco-normal { text-decoration: line-through; color: #777; font-size: 12px; font-weight: 400; display: block; }
        .col-qtd { width: 150px; }
        .promo-tag { 
            color: var(--cor-sucesso); 
            font-weight: 600; 
            font-size: 12px;
            background: rgba(0, 240, 181, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
        }
        .item-poupanca-cell { color: var(--cor-sucesso); }
        
        /* RESPONSIVIDADE (MOBILE) */
        @media (max-width: 768px) {
            body { padding: 10px; }
            .container { padding: 20px; margin: 10px auto; }
            header h1 { font-size: 24px; }
            header { flex-direction: column; align-items: flex-start; gap: 10px; }
            
            .compra-header .totais { 
                flex-direction: column; 
                gap: 15px; 
            }
            .compra-header { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Detalhes da Compra 🛒</h1>
            <a href="logout.php">Sair</a>
        </header>
        
        <div class="content">
            <a href="dashboard.php" class="link-voltar">
                &larr; Voltar para o Histórico
            </a>

            <?php if ($erro): ?>
                <div class="error-box">⚠️ <?php echo htmlspecialchars($erro); ?></div>
            <?php elseif ($compra): ?>
                
                <div class="compra-header">
                    <h2><?php echo htmlspecialchars($compra['estabelecimento_nome']); ?></h2>
                    <p>
                        Realizada em: <?php echo (new DateTime($compra['data_fim']))->format('d/m/Y \à\s H:i'); ?>
                    </p>
                    
                    <div class="totais">
                        <div>Gasto Total <span class="gasto">R$ <?php echo number_format($compra['total_gasto'], 2, ',', '.'); ?></span></div>
                        <div>Total Poupado <span class="poupado">R$ <?php echo number_format($compra['total_poupado'], 2, ',', '.'); ?></span></div>
                    </div>
                </div>

                <h3>Itens Registados (<?php echo count($itens); ?>)</h3>
                
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th class="col-qtd">Quantidade</th>
                                <th class="col-valor">Preço Pago (Total)</th>
                                <th class="col-poupanca">Poupança (Total)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itens as $item): 
                                // Calcula a poupança TOTAL do item
                                $poupancaTotal = 0;
                                if ($item['em_promocao'] && $item['preco_normal'] > $item['preco']) {
                                    // Diferença unitária * quantidade total
                                    $poupancaUnitaria = (float)$item['preco_normal'] - (float)$item['preco'];
                                    $poupancaTotal = $poupancaUnitaria * (int)$item['quantidade'];
                                }
                                // Calcula o preço total pago para display
                                $precoTotalPago = (float)$item['preco'] * (int)$item['quantidade'];
                            ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($item['produto_nome']); ?>
                                        <?php if ($item['em_promocao']): ?>
                                            <span class="promo-tag">PROMO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-qtd">
                                        <?php echo htmlspecialchars($item['quantidade_desc']); ?> 
                                        <span style="color: var(--cor-texto-secundaria);">(<?php echo $item['quantidade']; ?> unid.)</span>
                                    </td>
                                    <td class="col-valor">
                                        R$ <?php echo number_format($precoTotalPago, 2, ',', '.'); ?> 
                                        <span class="col-preco-normal">Unid: R$ <?php echo number_format($item['preco'], 2, ',', '.'); ?></span>
                                    </td>
                                    <td class="col-poupanca item-poupanca-cell">
                                        <?php if ($poupancaTotal > 0): ?>
                                            R$ <?php echo number_format($poupancaTotal, 2, ',', '.'); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($itens)): ?>
                                 <tr><td colspan="4" style="text-align: center; color: var(--cor-texto-secundaria);">Nenhum item foi registado nesta compra.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>