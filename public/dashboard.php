<?php
// ---
// /public/dashboard.php
// O Painel do Utilizador (v2.0 - Dark Theme & Responsivo)
// ---

session_start();

// 1. O Segurança: Verifica se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    // Redireciona para o login se não houver sessão ativa
    header("Location: auth.php"); 
    exit;
}

// 2. Se está logado, carrega os dados
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\Usuario;
use App\Models\Compra;

try {
    $pdo = getDbConnection();
    $userId = $_SESSION['user_id'];
    $userNome = $_SESSION['user_nome'];

    // 3. Busca o histórico de compras
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
    <title>Meu Painel - Merkee</title>
    <style>
        /* Paleta de Cores Dark Theme */
        :root {
            --cor-fundo: #121212;
            --cor-fundo-card: #1f1f1f;
            --cor-texto-principal: #f0f0f0;
            --cor-texto-secundaria: #a0a0a0;
            --cor-principal: #0a9396; /* Azul Água */
            --cor-sucesso: #90ee90; /* Verde Claro para Poupança */
            --cor-alerta: #ff6b6b; /* Vermelho Claro para Gasto */
            --cor-borda: #444444;
            --cor-hover: #3c3c3c;
        }

        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            background: var(--cor-fundo); 
            margin: 0; 
            padding: 20px; 
            color: var(--cor-texto-principal); 
        }
        .container { 
            max-width: 1100px; 
            margin: 20px auto; 
            background: var(--cor-fundo-card);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5); /* Sombra intensa para o dark mode */
        }

        /* HEADER */
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
            color: var(--cor-texto-principal); 
            text-decoration: none; 
            font-weight: 600; 
            padding: 8px 15px;
            border: 1px solid var(--cor-borda);
            border-radius: 6px;
            transition: background 0.2s;
        }
        header a:hover {
            background: var(--cor-principal);
            border-color: var(--cor-principal);
            color: var(--cor-fundo-card);
        }

        /* TÍTULOS e ESTADO VAZIO */
        h2 { color: var(--cor-texto-principal); font-weight: 600; margin: 0 0 10px 0; font-size: 24px; }
        h3 { color: var(--cor-principal); margin: 30px 0 15px 0; font-size: 18px; border-left: 4px solid var(--cor-principal); padding-left: 10px; }
        
        .empty-state { 
            background: #252525; 
            padding: 40px; 
            text-align: center; 
            border-radius: 8px; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.5); 
            border: 1px dashed var(--cor-borda);
        }
        .empty-state p { color: var(--cor-texto-secundaria); }

        .error-box { 
            background: #442222; 
            border: 1px solid var(--cor-alerta); 
            color: var(--cor-alerta); 
            padding: 15px; 
            border-radius: 8px; 
            margin-bottom: 20px;
        }

        /* TABELA */
        .table-wrapper {
            overflow-x: auto; /* Permite scroll horizontal em telas pequenas */
            margin-top: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }

        table { 
            width: 100%; 
            min-width: 600px; /* Garante que a tabela não fique espremida */
            border-collapse: collapse; 
            background: var(--cor-fundo-card);
        }
        th, td { 
            padding: 15px; 
            text-align: left; 
            border-bottom: 1px solid var(--cor-borde); 
            color: var(--cor-texto-principal);
        }
        th { 
            background-color: #252525; 
            font-size: 14px; 
            color: var(--cor-principal); 
            font-weight: 600;
            border-bottom: 2px solid var(--cor-principal);
        }

        .col-valor { text-align: right; font-weight: 600; color: var(--cor-alerta); }
        .col-poupanca { text-align: right; font-weight: 600; color: var(--cor-sucesso); }
        .col-data { width: 140px; color: var(--cor-texto-secundaria); font-size: 14px; }
        
        .clickable-row { cursor: pointer; }
        .clickable-row:hover { 
            background-color: var(--cor-hover);
        }
        .clickable-row a { 
            text-decoration: none; 
            color: var(--cor-texto-principal); 
            font-weight: 600; 
            display: block; 
        }

        /* RESPONSIVIDADE (MOBILE) */
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
            <h1>Merkee Dashboard 📊</h1>
            <a href="logout.php">Sair</a>
        </header>
        
        <div class="content">
            <h2>Olá, <?php echo htmlspecialchars(explode(' ', $userNome)[0]); ?>!</h2>
            
            <?php if (isset($erro)): ?>
                <div class="error-box">⚠️ <?php echo $erro; ?></div>
            <?php endif; ?>

            <h3>Histórico de Compras Finalizadas</h3>
            
            <?php if (empty($compras)): ?>
                
                <div class="empty-state">
                    <p style="font-size: 1.1em; font-weight: 500;">Ainda não tem compras finalizadas. 😕</p>
                    <p>Comece a poupar hoje mesmo! Vá ao WhatsApp e envie *"login"* para aceder ao link mágico de início de compra.</p>
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
                </div>

            <?php endif; ?>
            </div>
    </div>

    <script>
document.addEventListener("DOMContentLoaded", function() {
    const rows = document.querySelectorAll("tr.clickable-row");
    rows.forEach(row => {
        row.addEventListener("click", (event) => {
            // Previne que o clique na célula com o link cause navegação dupla
            if (event.target.tagName.toLowerCase() !== 'a') {
                window.location.href = row.dataset.href;
            }
        });
    });
});
</script>

</body>
</html>