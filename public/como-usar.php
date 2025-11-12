<?php
// ---
// /public/manual.php
// Manual de Uso do WalletlyBot (v9 Aurora Glass)
// ---

// Link para a página inicial (pode mudar se necessário)
$link_inicio = "index.php"; 
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual de Uso - WalletlyBot</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        /* Reset de Box-sizing */
        *, *::before, *::after {
            box-sizing: border-box;
        }

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
        
        /* Header (do index.php) */
        header {
            padding: 20px 0;
            border-bottom: 1px solid var(--cor-borda);
            background: rgba(30, 31, 48, 0.7); /* Glassmorphism */
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            position: sticky;
            top: 0;
            z-index: 50;
            margin-bottom: 30px;
        }
        header .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header h1 {
            font-size: 24px;
            color: var(--cor-principal);
            margin: 0;
            font-weight: 700;
        }
        .cta-header {
            background: var(--cor-principal);
            color: #fff;
            padding: 8px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        .cta-header:hover {
            background: var(--cor-principal-hover);
            box-shadow: 0 0 10px rgba(122, 92, 255, 0.5);
        }

        /* Container Principal (do dashboard.php) */
        .container { 
            max-width: 1100px; 
            margin: 20px auto; 
            background: var(--cor-fundo-card);
            backdrop-filter: blur(10px);
            border: 1px solid var(--cor-borda);
            padding: 30px 40px;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.7); 
        }

        /* Estilos do Manual */
        .content h2 {
            color: var(--cor-principal); /* Roxo */
            font-size: 26px;
            font-weight: 700;
            border-bottom: 2px solid var(--cor-borda);
            padding-bottom: 10px;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        
        .content .secao {
            margin-bottom: 40px;
        }

        .content .secao h3 {
            color: var(--cor-sucesso); /* Verde Menta */
            font-size: 22px;
            font-weight: 600;
            margin-top: 30px;
            margin-bottom: 15px;
        }
        
        .content .secao p {
            color: var(--cor-texto-secundaria);
            line-height: 1.7;
            font-size: 16px;
            margin-bottom: 15px;
        }
        
        .content .secao p code {
            background: var(--cor-fundo-card-solido);
            border: 1px solid var(--cor-borda);
            color: var(--cor-sucesso);
            padding: 3px 8px;
            border-radius: 5px;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 0.95em;
        }

        /* Footer (do index.php) */
        footer {
            text-align: center;
            padding: 30px 0;
            background: transparent;
            font-size: 14px;
            color: var(--cor-texto-secundaria);
            margin-top: 30px;
        }
        footer a {
            color: var(--cor-principal);
            text-decoration: none;
        }
        footer a:hover {
            text-decoration: underline;
        }
        
        /* Responsividade */
        @media (max-width: 768px) {
            body { padding: 10px; }
            header { margin-bottom: 20px; }
            header .header-container { padding: 0 15px; }
            header h1 { font-size: 20px; }
            .container { padding: 20px; margin: 10px auto; }
            .content h2 { font-size: 22px; }
            .content .secao h3 { font-size: 18px; }
        }
    </style>
</head>
<body>

    <header>
        <div class="header-container">
            <h1>Manual de Uso 📖</h1>
            <a href="<?php echo $link_inicio; ?>" class="cta-header">&larr; Voltar ao Início</a>
        </div>
    </header>

    <div class="container">
        <div class="content">

            <div class="secao">
                <h2>1. Comandos do Menu Principal (Estado: INICIAL)</h2>
                <p>Estes são os comandos que funcionam a qualquer momento, quando o utilizador não está no meio de uma compra.</p>

                <h3><code>lista</code></h3>
                <p><strong>Explicação:</strong> Este comando coloca o bot no estado <code>CRIANDO_LISTA</code>. O bot responde a confirmar e tudo o que o utilizador digitar a seguir é adicionado como um item na lista de compras.</p>

                <h3><code>login</code> / <code>painel</code> / <code>config</code> / <code>ajuda</code></h3>
                <p><strong>Explicação:</strong> Todos estes comandos ativam o <code>ConfigHandler</code>. A função principal dele é enviar o "Link Mágico" para o utilizador aceder ao <code>dashboard.php</code>.</p>
            </div>

            <div class="secao">
                <h2>2. Comandos Durante a Compra (Estado: COMPRA_ATIVA)</h2>
                <p>Estes comandos só funcionam depois do utilizador ter iniciado uma compra (enviando uma localização e escolhendo um supermercado).</p>

                <h3><code>2x leite 5,00</code> (ou <code>1 arroz 19.90</code>, <code>5 pao 0.50</code>)</h3>
                <p><strong>Explicação:</strong> Este é o comando principal de registo. Não é uma palavra-chave, mas sim um padrão. O <code>ItemParserService</code> é ativado para tentar extrair a quantidade, o nome e o preço de qualquer texto que não seja um dos comandos abaixo.</p>

                <h3><code>finalizar</code></h3>
                <p><strong>Explicação:</strong> Este comando encerra a compra. O bot calcula os totais (gasto e poupança) e guarda a compra como "finalizada" no banco de dados. O utilizador volta ao estado <code>INICIAL</code>.</p>

                <h3><code>remover</code></h3>
                <p><strong>Explicação:</strong> Este comando apaga o último item registado. O bot confirma qual item foi removido.</p>

E<h3><code>cancelar</code></h3>
                <p><strong>Explicação:</strong> Este comando cancela a compra inteira. Todos os itens são apagados e o utilizador volta ao estado <code>INICIAL</code>.</p>
            </div>

            <div class="secao">
                <h2>3. Comandos Durante a Criação da Lista (Estado: CRIANDO_LISTA)</h2>
                <p>Estes comandos só funcionam depois do utilizador ter escrito <code>lista</code>.</p>

                <h3><code>Arroz</code> (ou <code>Leite Desnatado</code>, <code>Pão Francês</code>)</h3>
                <p><strong>Explicação:</strong> Qualquer texto que não seja um comando (como <code>finalizar</code> ou <code>remover</code>) é adicionado como um item à lista.</p>

                <h3><code>remover</code></h3>
                <p><strong>Explicação:</strong> Apaga o último item adicionado à lista.</p>

                <h3><code>finalizar</code> / <code>cancelar</code></h3>
                <p><strong>Explicação:</strong> Ambos os comandos saem do modo de criação de lista e retornam o utilizador ao estado <code>INICIAL</code>.</p>
            </div>

        </div>
    </div>

    <footer>
        <div class="container">
            &copy; <?php echo date("Y"); ?> WalletlyBot | Um Micro-SaaS by <a href="#">Oliveira & Giovanini Software</a>.
        </div>
    </footer>

</body>
</html>