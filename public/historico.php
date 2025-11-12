<?php
// ---
// /public/historico.php
// O Histórico de Compras Completo (v9 Aurora Glass)
// ---

session_start();

// 1. Segurança
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php"); 
    exit;
}

// 2. Carregar dados
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\Compra;

try {
    $pdo = getDbConnection();
    $userId = $_SESSION['user_id'];

    // 3. Busca TODO o histórico (sem limite)
    $compras = Compra::findAllCompletedByUser($pdo, $userId);

} catch (Exception $e) {
    $erro = "Erro ao carregar os dados: " . $e->getMessage();
    $compras = [];
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Compras - WalletlyBot</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
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
        header a, .link-voltar { color: var(--cor-texto-principal); text-decoration: none; font-weight: 600; padding: 8px 15px; border: 1px solid var(--cor-borda); border-radius: 6px; transition: all 0.2s; }
        header a:hover, .link-voltar:hover { background: var(--cor-principal); border-color: var(--cor-principal); color: #fff; }
        
        .link-voltar { display: inline-block; margin-bottom: 25px; }
        h3 { color: var(--cor-principal); margin: 30px 0 15px 0; font-size: 18px; border-left: 4px solid var(--cor-principal); padding-left: 10px; }
        .error-box { background: rgba(255, 92, 122, 0.1); border: 1px solid var(--cor-alerta); color: var(--cor-alerta); padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        
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
            <h1>Histórico Completo Archive</h1>
            <a href="logout.php">Sair</a>
        </header>
        
        <div class="content">
            <a href="dashboard.php" class="link-voltar">
                &larr; Voltar para o Dashboard
            </a>

            <?php if (isset($erro)): ?>
                <div class="error-box">⚠️ <?php echo $erro; ?></div>
            <?php endif; ?>

            <h3>Todas as Compras (<?php echo count($compras); ?>)</h3>
            
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
                        
                        <?php if (empty($compras)): ?>
                             <tr><td colspan="4" style="text-align: center; color: var(--cor-texto-secundaria);">Nenhum histórico de compra encontrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const rows = document.querySelectorAll("tr.clickable-row");
        rows.forEach(row => {
            row.addEventListener("click", (event) => {
                if (event.target.tagName.toLowerCase() !== 'a') {
                    window.location.href = row.dataset.href;
                }
            });
        });
    });
    </script>
</body>
</html>