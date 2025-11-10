<?php
// ---
// /public/dashboard.php
// O Painel do Utilizador (v1.1 - Com Histórico)
// ---

session_start();

// 1. O Segurança: Verifica se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    die("Acesso negado. Por favor, faz login através do link enviado no teu WhatsApp.");
}

// 2. Se está logado, carrega os dados
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\Usuario;
use App\Models\Compra; // (NOVO) Precisamos do modelo Compra

try {
    $pdo = getDbConnection();
    $userId = $_SESSION['user_id'];
    $userNome = $_SESSION['user_nome'];

    // --- (INÍCIO DA NOVA LÓGICA) ---
    // 3. Busca o histórico de compras
    $compras = Compra::findAllCompletedByUser($pdo, $userId);
    // --- (FIM DA NOVA LÓGICA) ---

} catch (Exception $e) {
    // Se a base de dados falhar, mostra um erro amigável
    $erro = "Erro ao carregar os dados: " . $e->getMessage();
    $compras = [];
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Painel - Merkee</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: 20px auto; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        header h1 { color: #005f73; margin: 0; }
        header a { color: #d9534f; text-decoration: none; font-weight: 500; }
        .content { margin-top: 20px; }
        
        /* Estilos da Tabela (NOVO) */
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f9f9f9; font-size: 14px; color: #555; }
        td { font-size: 15px; }
        .col-valor { text-align: right; font-weight: 500; }
        .col-poupanca { text-align: right; font-weight: 500; color: #2a9d8f; }
        .col-data { width: 120px; color: #777; }
        
        .empty-state { background: #fff; padding: 40px; text-align: center; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .error-box { background: #f2dede; border: 1px solid #ebccd1; color: #a94442; padding: 15px; border-radius: 8px; }

        .clickable-row { cursor: pointer; }
        .clickable-row:hover { background-color: #f5f5f5; }
        .clickable-row a { text-decoration: none; color: #005f73; font-weight: 500; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>O Meu Painel</h1>
            <a href="logout.php">Sair</a>
        </header>
        
        <div class="content">
            <h2>Olá, <?php echo htmlspecialchars(explode(' ', $userNome)[0]); ?>!</h2>
            
            <?php if (isset($erro)): ?>
                <div class="error-box"><?php echo $erro; ?></div>
            <?php endif; ?>

            <h3>Histórico de Compras</h3>
            
            <?php if (empty($compras)): ?>
                
                <div class="empty-state">
                    <p>Ainda não tens compras finalizadas. 😕</p>
                    <p>Vai ao WhatsApp e envia "iniciar compra" para registares a tua primeira!</p>
                </div>

            <?php else: ?>

                <table>
                    <thead>
                        <tr>
                            <th>Estabelecimento</th>
                            <th class="col-data">Data</th>
                            <th class="col-valor">Total Gasto</th>
                            <th class="col-poupanca">Total Poupado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($compras as $compra): ?>
                            <tr class="clickable-row" data-href="detalhe_compra.php?id=<?php echo $compra['id']; ?>">
                                <td>
                                    <a href="detalhe_compra.php?id=<?php echo $compra['id']; ?>">
                                        <?php echo htmlspecialchars($compra['estabelecimento_nome']); ?>
                                    </a>
                                </td>
                                <td class="col-data"><?php echo (new DateTime($compra['data_fim']))->format('d/m/Y'); ?></td>
                                <td class="col-valor">R$ <?php echo number_format($compra['total_gasto'], 2, ',', '.'); ?></td>
                                <td class="col-poupanca">R$ <?php echo number_format($compra['total_poupado'], 2, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endif; ?>
            </div>
    </div>

    <script>
document.addEventListener("DOMContentLoaded", function() {
    const rows = document.querySelectorAll("tr.clickable-row");
    rows.forEach(row => {
        row.addEventListener("click", () => {
            window.location.href = row.dataset.href;
        });
    });
});
</script>

</body>
</html>