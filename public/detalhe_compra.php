<?php
// ---
// /public/detalhe_compra.php
// (Funcionalidade #10: Detalhe de Compra - UI/UX Nível 10)
// ---

session_start();

// 1. Segurança: Verifica se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    die("Acesso negado. Por favor, faz login através do link enviado no teu WhatsApp.");
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
    <title>Detalhe da Compra - Merkee</title>
    <style>
        /* Variáveis CSS para consistência com o Dashboard */
        :root {
            --cor-principal: #005f73;
            --cor-secundaria: #0a9396;
            --cor-fundo: #f8f9fa;
            --cor-sucesso: #2a9d8f;
            --cor-alerta: #e63946;
            --cor-texto: #343a40;
            --cor-borda: #dee2e6;
        }

        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            background: var(--cor-fundo); 
            margin: 0; 
            padding: 20px; 
            color: var(--cor-texto); 
        }
        .container { 
            max-width: 1000px; 
            margin: 20px auto; 
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 3px solid var(--cor-borda); 
            padding-bottom: 20px; 
            margin-bottom: 30px;
        }
        header h1 { 
            color: var(--cor-principal); 
            margin: 0; 
            font-size: 28px; 
        }
        header a { 
            color: var(--cor-principal); 
            text-decoration: none; 
            font-weight: 600; 
            padding: 8px 15px;
            border: 1px solid var(--cor-principal);
            border-radius: 6px;
            transition: background 0.2s;
        }
        header a:hover {
            background: var(--cor-principal);
            color: #fff;
        }
        .content { margin-top: 20px; }
        
        /* Cabeçalho da Compra como Card de Destaque */
        .compra-header { 
            background: #f1f7f9; /* Fundo mais claro para destaque */
            padding: 25px; 
            border-radius: 10px; 
            border: 1px solid #d4e3e8;
            margin-bottom: 30px; 
        }
        .compra-header h2 { 
            margin: 0 0 5px 0; 
            color: var(--cor-principal); 
            font-size: 24px;
        }
        .compra-header p { 
            margin: 0 0 15px 0; 
            font-size: 15px; 
            color: #777; 
        }
        .compra-header .totais { 
            display: flex; 
            gap: 40px; 
            margin-top: 20px; 
        }
        .compra-header .totais div { 
            font-size: 15px; 
        }
        .compra-header .totais span { 
            display: block; 
            font-size: 24px; 
            font-weight: bold; 
            margin-top: 5px;
        }
        .totais .gasto { color: var(--cor-alerta); }
        .totais .poupado { color: var(--cor-sucesso); }
        
        /* Tabela de Itens */
        h3 { color: var(--cor-texto); margin: 30px 0 15px 0; font-size: 18px; border-left: 4px solid var(--cor-secundaria); padding-left: 10px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--cor-borda); }
        th { background-color: var(--cor-fundo); font-size: 14px; color: var(--cor-secundaria); font-weight: 600; }
        td { font-size: 15px; }
        
        .col-valor { text-align: right; font-weight: 600; color: var(--cor-alerta); }
        .col-poupanca { text-align: right; font-weight: 600; color: var(--cor-sucesso); }
        .col-preco-normal { text-decoration: line-through; color: #777; font-size: 12px; font-weight: 400; display: block; }
        .col-qtd { width: 150px; }

        .error-box { background: #f8d7da; border: 1px solid #f5c6cb; color: var(--cor-alerta); padding: 15px; border-radius: 8px; }
        
        /* Botão Voltar (Link) */
        .link-voltar {
            color: var(--cor-secundaria);
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 25px;
            transition: color 0.2s;
        }
        .link-voltar:hover {
            color: var(--cor-principal);
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>O Meu Painel 🛒</h1>
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
                    <h2>Detalhes da Compra em: <?php echo htmlspecialchars($compra['estabelecimento_nome']); ?></h2>
                    <p>
                        Realizada em: <?php echo (new DateTime($compra['data_fim']))->format('d/m/Y \à\s H:i'); ?>
                    </p>
                    
                    <div class="totais">
                        <div>Gasto Total <span class="gasto">R$ <?php echo number_format($compra['total_gasto'], 2, ',', '.'); ?></span></div>
                        <div>Total Poupado <span class="poupado">R$ <?php echo number_format($compra['total_poupado'], 2, ',', '.'); ?></span></div>
                    </div>
                </div>

                <h3>Itens Registados (<?php echo count($itens); ?>)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th class="col-qtd">Quantidade</th>
                            <th class="col-valor">Preço Pago (Unid.)</th>
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
                                        <span style="display: block; font-size: 11px; color: var(--cor-sucesso); font-weight: 600;">✨ Em Promoção!</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-qtd">
                                    <?php echo htmlspecialchars($item['quantidade_desc']); ?> 
                                    <span style="color: #777;">(<?php echo $item['quantidade']; ?> unidades)</span>
                                </td>
                                <td class="col-valor">
                                    R$ <?php echo number_format($precoTotalPago, 2, ',', '.'); ?> 
                                    <span class="col-preco-normal">Unid: R$ <?php echo number_format($item['preco'], 2, ',', '.'); ?></span>
                                </td>
                                <td class="col-poupanca">
                                    <?php if ($poupancaTotal > 0): ?>
                                        R$ <?php echo number_format($poupancaTotal, 2, ',', '.'); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($itens)): ?>
                             <tr><td colspan="4" style="text-align: center; color: #777;">Nenhum item foi registado nesta compra.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>