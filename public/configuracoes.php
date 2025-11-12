<?php
// ---
// /public/configuracoes.php
// (Funcionalidade #17: Gerenciar Perfil e Configurações + #18: Mercado Favorito)
// ---

session_start();

// 1. Segurança: Verifica se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php"); 
    exit;
}

// 2. Carrega as dependências
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\Usuario;
use App\Models\Estabelecimento; // Precisa do modelo Estabelecimento para buscar a lista de mercados

$erro = null;
$sucesso = null;
$userId = (int)$_SESSION['user_id'];
$pdo = getDbConnection();

// --- NOVO: Busca a lista de mercados que o utilizador usou ---
$sqlMercados = "
    SELECT DISTINCT e.id, e.nome, e.cidade 
    FROM estabelecimentos e
    JOIN compras c ON e.id = c.estabelecimento_id
    WHERE c.usuario_id = ?
    ORDER BY e.nome ASC
";
$stmtMercados = $pdo->prepare($sqlMercados);
$stmtMercados->execute([$userId]);
$mercadosDoUsuario = $stmtMercados->fetchAll(PDO::FETCH_ASSOC);


// 3. Lógica de Processamento do Formulário (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $usuario = Usuario::findById($pdo, $userId);
        if (!$usuario) throw new Exception("Usuário não encontrado.");
        
        // A. Processar Nome
        $novoNome = trim($_POST['nome'] ?? '');
        if (!empty($novoNome) && $novoNome !== $usuario->nome) {
            $usuario->updateNameAndConfirm($pdo, $novoNome);
        }
        
        // B. Processar Receber Alertas
        $receberAlertas = isset($_POST['receber_alertas']);
        if ($receberAlertas !== $usuario->receber_alertas) {
            $usuario->updateConfig($pdo, 'receber_alertas', $receberAlertas);
        }

        // C. Processar Receber Dicas
        $receberDicas = isset($_POST['receber_dicas']);
        if ($receberDicas !== $usuario->receber_dicas) {
            $usuario->updateConfig($pdo, 'receber_dicas', $receberDicas);
        }

        // D. FEATURE #18: Processar Mercado Favorito
        $mercadoFavoritoId = (int)($_POST['mercado_favorito'] ?? 0);
        $usuario->updateFavoriteMarket($pdo, $mercadoFavoritoId);
        
        $sucesso = "Configurações guardadas com sucesso!";
        $_SESSION['user_nome'] = $usuario->nome; 

    } catch (Exception $e) {
        $erro = "Erro ao guardar as configurações: " . $e->getMessage();
    }
}

// 4. Carrega os dados atuais do utilizador
$usuario = Usuario::findById($pdo, $userId);
if (!$usuario) {
    $erro = "Erro crítico: Não foi possível carregar os dados do utilizador.";
    $usuario = (object)['nome' => 'Usuário', 'receber_alertas' => false, 'receber_dicas' => false, 'mercado_favorito_id' => null];
}

$nomeCurto = htmlspecialchars(explode(' ', $usuario->nome)[0]);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - WalletlyBot</title>
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
        .container { max-width: 700px; margin: 20px auto; background: var(--cor-fundo-card); backdrop-filter: blur(10px); border: 1px solid var(--cor-borda); padding: 30px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.7); }
        
        /* HEADER */
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--cor-borda); padding-bottom: 20px; margin-bottom: 30px; }
        header h1 { color: var(--cor-principal); margin: 0; font-size: 28px; }
        header a { color: var(--cor-texto-principal); text-decoration: none; font-weight: 600; padding: 8px 15px; border: 1px solid var(--cor-borda); border-radius: 6px; transition: all 0.2s; }
        header a:hover { background: var(--cor-principal); border-color: var(--cor-principal); color: #fff; }

        h2 { color: var(--cor-texto-principal); font-weight: 600; margin: 0 0 10px 0; font-size: 24px; }
        h3 { color: var(--cor-principal); margin: 30px 0 15px 0; font-size: 18px; border-left: 4px solid var(--cor-principal); padding-left: 10px; }
        
        .error-box { background: rgba(255, 92, 122, 0.1); border: 1px solid var(--cor-alerta); color: var(--cor-alerta); padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .success-box { background: rgba(0, 240, 181, 0.1); border: 1px solid var(--cor-sucesso); color: var(--cor-sucesso); padding: 15px; border-radius: 8px; margin-bottom: 20px; }

        /* FORM STYLING */
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--cor-texto-principal); }
        input[type="text"], select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--cor-borda);
            border-radius: 6px;
            background-color: var(--cor-fundo-card-solido);
            color: var(--cor-texto-principal);
            font-size: 16px;
            outline: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        /* CHECKBOX/SWITCH STYLING */
        .checkbox-group {
            background: var(--cor-fundo-card-solido);
            border: 1px solid var(--cor-borda);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .checkbox-group p { 
            margin: 0; 
            font-size: 15px; 
            font-weight: 500;
        }
        .checkbox-group small {
            display: block;
            color: var(--cor-texto-secundaria);
            margin-top: 5px;
        }

        /* TOGGLE SWITCH (Requer apenas um checkbox escondido e um label) */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 25px;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--cor-borda);
            transition: .4s;
            border-radius: 25px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 19px;
            width: 19px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: var(--cor-sucesso); }
        input:checked + .slider:before { transform: translateX(25px); }

        /* SAVE BUTTON */
        .save-button {
            width: 100%;
            padding: 15px;
            background-color: var(--cor-principal);
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
            font-size: 18px;
            transition: background-color 0.2s, transform 0.2s;
            margin-top: 20px;
        }
        .save-button:hover {
            background-color: #6a4bff;
            transform: translateY(-2px);
        }

        /* RESPONSIVIDADE */
        @media (max-width: 600px) {
            .container { padding: 15px; }
            .checkbox-group { flex-direction: column; align-items: flex-start; gap: 10px; }
            .toggle-switch { align-self: flex-end; }
        }

    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Configurações do Perfil ⚙️</h1>
            <a href="dashboard.php">← Voltar ao Dashboard</a>
        </header>
        
        <div class="content">
            
            <?php if (isset($erro)): ?>
                <div class="error-box">⚠️ <?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>
            <?php if (isset($sucesso)): ?>
                <div class="success-box">✅ <?php echo htmlspecialchars($sucesso); ?></div>
            <?php endif; ?>

            <form action="configuracoes.php" method="POST">
                
                <h3>Dados Pessoais</h3>
                <div class="form-group">
                    <label for="nome">Como gostas de ser chamado (a)?</label>
                    <input type="text" id="nome" name="nome" value="<?php echo htmlspecialchars($usuario->nome); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="mercado_favorito">Mercado Favorito</label>
                    <select id="mercado_favorito" name="mercado_favorito">
                        <option value="0">--- Nenhum (Escolha um Mercado) ---</option>
                        
                        <?php foreach ($mercadosDoUsuario as $mercado): 
                            $isSelected = ($usuario->mercado_favorito_id == $mercado['id']) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $mercado['id']; ?>" <?php echo $isSelected; ?>>
                                <?php echo htmlspecialchars($mercado['nome']); ?> (<?php echo htmlspecialchars($mercado['cidade']); ?>)
                            </option>
                        <?php endforeach; ?>

                    </select>
                    <small style="color: var(--cor-texto-secundaria);">Isto ajuda o bot a priorizar este mercado em alertas e comparações.</small>
                </div>
                <h3>Preferências de Notificação</h3>
                
                <div class="checkbox-group">
                    <div>
                        <p>Receber Alertas de Preço</p>
                        <small>Notificações automáticas se um produto que compraste subir muito de preço.</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="receber_alertas" value="1" <?php echo $usuario->receber_alertas ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div class="checkbox-group">
                    <div>
                        <p>Receber Dicas de Economia</p>
                        <small>Dicas semanais sobre novos recursos e tendências de preço (via CRON).</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="receber_dicas" value="1" <?php echo $usuario->receber_dicas ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <button type="submit" class="save-button">Guardar Configurações</button>
            </form>
        </div>
    </div>
</body>
</html>