<?php
// ---
// /public/detalhe_compra.php
// (Funcionalidade #10: Detalhe de Compra)
// ---

session_start();

// 1. Segurança: Verifica se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    die("Acesso negado. Por favor, faz login através do link enviado no teu WhatsApp.");
}

// 2. Carrega tudo
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\Compra; // Precisamos do modelo Compra

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
            // Se não encontrou, ou a compra não é deste utilizador
            $erro = "Compra não encontrada ou não pertence a si.";
        } else {
            // 4. Se a compra é válida, busca os itens dela
            $itens = Compra::findItemsByCompraId($pdo, $compraId);
        }
        
    } catch (Exception $e) {
        $erro = "Erro ao carregar os dados: " . $e.getMessage();
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
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: 20px auto; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        header h1 { color: #005f73; margin: 0; }
        header a { color: #007bff; text-decoration: none; font-weight: 500; }
        .content { margin-top: 20px; }
        
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f9f9f9; font-size: 14px; color: #555; }
        td { font-size: 15px; }
        .col-valor { text-align: right; font-weight: 500; }
        .col-poupanca { font-weight: 500; color: #2a9d8f; }
        .col-preco-normal { text-decoration: line-through; color: #777; font-size: 13px; }
        
        .compra-header { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .compra-header h2 { margin: 0 0 5px 0; }
        .compra-header p { margin: 0; font-size: 16px; color: #555; }
        .compra-header .totais { display: flex; gap: 30px; margin-top: 15px; }
        .compra-header .totais div { font-size: 15px; }
        .compra-header .totais span { display: block; font-size: 20px; font-weight: bold; }
        .totais .gasto { color: #d9534f; }
        .totais .poupado { color: #2a9d8f; }

        .error-box { background: #f2dede; border: 1px solid #ebccd1; color: #a94442; padding: 15px; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>O Meu Painel</h1>
            <a href="logout.php">Sair</a>
        </header>
        
        <div class="content">
            <p style="margin-bottom: 20px;">
                <a href="dashboard.php">&larr; Voltar para o Histórico</a>
            </p>

            <?php if ($erro): ?>
                <div class="error-box"><?php echo htmlspecialchars($erro); ?></div>
            <?php elseif ($compra): ?>
                
                <div class="compra-header">
                    <h2><?php echo htmlspecialchars($compra['estabelecimento_nome']); ?></h2>
                    <p>Compra realizada em: <?php echo (new DateTime($compra['data_fim']))->format('d/m/Y \à\s H:i'); ?></p>
                    
                    <div class="totais">
                        <div>Total Gasto <span class="gasto">R$ <?php echo number_format($compra['total_gasto'], 2, ',', '.'); ?></span></div>
                        <div>Total Poupado <span class="poupado">R$ <?php echo number_format($compra['total_poupado'], 2, ',', '.'); ?></span></div>
                    </div>
                </div>

                <h3>Itens Registados</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th>Quantidade</th>
                            <th class="col-valor">Preço (Unitário)</th>
                            <th class="col-poupanca">Poupança (Unitária)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['produto_nome']); ?></td>
                                <td><?php echo htmlspecialchars($item['quantidade_desc']); ?> (<?php echo $item['quantidade']; ?>)</td>
                                <td class="col-valor">
                                    R$ <?php echo number_format($item['preco'], 2, ',', '.'); ?>
                                    <?php if ($item['em_promocao']): ?>
                                        <span class="col-preco-normal"> (era R$ <?php echo number_format($item['preco_normal'], 2, ',', '.'); ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-poupanca">
                                    <?php if ($item['em_promocao']): ?>
                                        R$ <?php echo number_format($item['preco_normal'] - $item['preco'], 2, ',', '.'); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>