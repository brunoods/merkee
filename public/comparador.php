<?php
// ---
// /public/comparador.php
// (Funcionalidade #11: Comparador de Produto / Histórico de Preços)
// ---

session_start();

// 1. Segurança
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php"); 
    exit;
}

// 2. Carrega as dependências
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\HistoricoPreco;
use App\Utils\StringUtils;
use DateTime;

$erro = null;
$resultados = null;
$produtoPesquisado = $_GET['produto'] ?? '';
$userId = (int)$_SESSION['user_id'];

if (!empty($produtoPesquisado)) {
    try {
        $pdo = getDbConnection();
        $nomeNormalizado = StringUtils::normalize($produtoPesquisado);

        // FEATURE #11: Buscar tendências de preço (simulando dados para um gráfico)
        // Usamos o método getPriceTrend do teu modelo para obter o histórico.
        // O terceiro argumento (compraAtualId) é 0 pois não estamos numa compra ativa.
        $resultados = HistoricoPreco::getPriceTrend($pdo, $userId, $nomeNormalizado, 0);

        if (empty($resultados)) {
            $erro = "Não encontramos registos para '{$produtoPesquisado}' no teu histórico.";
        }

    } catch (Exception $e) {
        $erro = "Erro ao buscar histórico: " . $e->getMessage();
        $resultados = [];
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparador de Preços - WalletlyBot</title>
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
        
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--cor-borda); padding-bottom: 20px; margin-bottom: 30px; }
        header h1 { color: var(--cor-principal); margin: 0; font-size: 28px; }
        header a { color: var(--cor-texto-principal); text-decoration: none; font-weight: 600; padding: 8px 15px; border: 1px solid var(--cor-borda); border-radius: 6px; transition: all 0.2s; }
        header a:hover { background: var(--cor-principal); border-color: var(--cor-principal); color: #fff; }

        h2 { color: var(--cor-texto-principal); font-weight: 600; margin: 0 0 10px 0; font-size: 24px; }
        h3 { color: var(--cor-principal); margin: 30px 0 15px 0; font-size: 18px; border-left: 4px solid var(--cor-principal); padding-left: 10px; }
        .error-box { background: rgba(255, 92, 122, 0.1); border: 1px solid var(--cor-alerta); color: var(--cor-alerta); padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        
        .search-form { display: flex; gap: 10px; margin-bottom: 30px; }
        .search-form input[type="text"] {
            flex-grow: 1;
            padding: 12px;
            border: 1px solid var(--cor-borda);
            border-radius: 6px;
            background-color: var(--cor-fundo-card-solido);
            color: var(--cor-texto-principal);
            font-size: 16px;
            outline: none;
            transition: border-color 0.2s;
        }
        .search-form input[type="text"]:focus { border-color: var(--cor-principal); }
        .search-form button {
            padding: 12px 25px;
            background-color: var(--cor-principal);
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s;
        }
        .search-form button:hover { background-color: #6a4bff; }

        /* TABELA DE RESULTADOS */
        .table-wrapper { overflow-x: auto; margin-top: 15px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.4); border: 1px solid var(--cor-borda); }
        table { width: 100%; min-width: 400px; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--cor-borda); color: var(--cor-texto-principal); }
        th { background-color: var(--cor-fundo-card-solido); font-size: 14px; text-transform: uppercase; color: var(--cor-principal); font-weight: 600; border-bottom: 2px solid var(--cor-principal); }
        .col-valor { text-align: right; font-weight: 600; color: var(--cor-alerta); }
        .col-data { width: 140px; color: var(--cor-texto-secundaria); font-size: 14px; }
        
        .empty-state { background: var(--cor-fundo-card-solido); padding: 40px; text-align: center; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.5); border: 1px dashed var(--cor-borda); }

        /* RESPONSIVIDADE */
        @media (max-width: 600px) {
            .search-form { flex-direction: column; }
            .search-form button { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Comparador de Preços 📈</h1>
            <a href="dashboard.php">← Voltar ao Dashboard</a>
        </header>
        
        <div class="content">
            <h2>Pesquisar Histórico de Produto</h2>
            <p style="color: var(--cor-texto-secundaria);">Digite o nome de um produto (ex: *Arroz Tio João 5kg*) para ver como o preço variou ao longo do tempo.</p>

            <form action="comparador.php" method="GET" class="search-form">
                <input type="text" name="produto" placeholder="Nome do produto para pesquisar..." value="<?php echo htmlspecialchars($produtoPesquisado); ?>" required>
                <button type="submit">Pesquisar</button>
            </form>
            
            <?php if (isset($erro)): ?>
                <div class="error-box">⚠️ <?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if ($resultados !== null): ?>
                
                <?php if (empty($resultados)): ?>
                     <div class="empty-state">
                        <p>Nenhum histórico encontrado para "<?php echo htmlspecialchars($produtoPesquisado); ?>".</p>
                        <p style="color: var(--cor-principal);">Lembre-se de registrar o produto no WhatsApp primeiro!</p>
                    </div>
                <?php else: ?>
                    
                    <h3>Histórico de Preços (Últimas 10 Compras)</h3>
                    
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Mercado</th>
                                    <th class="col-data">Data</th>
                                    <th class="col-valor">Preço Unitário</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultados as $resultado): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($resultado['estabelecimento_nome']); ?></td>
                                        <td class="col-data"><?php echo (new DateTime($resultado['data_compra']))->format('d/m/Y'); ?></td>
                                        <td class="col-valor">R$ <?php echo number_format($resultado['preco_unitario'], 2, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <h3 style="margin-top: 40px;">Tendência de Preço (Gráfico)</h3>
                    <div style="background: var(--cor-fundo-card-solido); border: 1px solid var(--cor-borda); border-radius: 8px; padding: 20px; height: 300px; display: flex; align-items: center; justify-content: center; color: var(--cor-texto-secundaria);">
                        [Área reservada para o gráfico de linha do tempo do preço do produto]
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>