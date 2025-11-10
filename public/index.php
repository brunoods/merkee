<?php
// ---
// /public/index.php
// Landing Page do WalletlyBot (Dark Theme & Responsivo)
// ---

// Variáveis de Configuração (Aqui, em um ambiente real, você leria do .env)
$whatsapp_number = "55XX9XXXXXXXX"; // Substitua pelo seu número do WhatsApp!
$whatsapp_link = "https://wa.me/" . $whatsapp_number . "?text=Ol%C3%A1%2C%20quero%20come%C3%A7ar%20a%20economizar!";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WalletlyBot | Seu Assistente de Compras no WhatsApp</title>
    <style>
        /* Paleta de Cores Dark Theme */
        :root {
            --cor-fundo: #121212;
            --cor-fundo-secundario: #1f1f1f;
            --cor-texto-principal: #f0f0f0;
            --cor-texto-secundaria: #a0a0a0;
            --cor-principal: #0a9396; /* Azul Água (Accent) */
            --cor-principal-hover: #077e81;
            --cor-sucesso: #90ee90; 
            --cor-borda: #444444;
        }

        body { 
            font-family: 'Inter', system-ui, sans-serif; 
            background: var(--cor-fundo); 
            color: var(--cor-texto-principal); 
            margin: 0; 
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* HEADER */
        header {
            padding: 20px 0;
            border-bottom: 1px solid var(--cor-borda);
            background: var(--cor-fundo-secundario);
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
        header .container {
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
            color: var(--cor-fundo);
            padding: 8px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.2s;
        }
        .cta-header:hover {
            background: var(--cor-principal-hover);
        }

        /* HERO SECTION */
        .hero {
            text-align: center;
            padding: 80px 20px;
            background: var(--cor-fundo);
        }
        .hero h2 {
            font-size: 48px;
            margin-bottom: 20px;
            font-weight: 800;
            color: var(--cor-sucesso);
        }
        .hero p {
            font-size: 20px;
            color: var(--cor-texto-secundaria);
            max-width: 700px;
            margin: 0 auto 30px;
        }
        .hero .cta-main {
            display: inline-block;
            background: var(--cor-principal);
            color: var(--cor-fundo);
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 22px;
            font-weight: 700;
            transition: background 0.2s, transform 0.2s;
        }
        .hero .cta-main:hover {
            background: var(--cor-principal-hover);
            transform: translateY(-2px);
        }
        
        /* FEATURES SECTION */
        .features {
            padding: 60px 0;
            background: var(--cor-fundo-secundario);
            border-top: 1px solid var(--cor-borda);
        }
        .features h2 {
            text-align: center;
            font-size: 36px;
            margin-bottom: 40px;
            color: var(--cor-principal);
        }
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }
        .feature-card {
            background: #252525;
            padding: 25px;
            border-radius: 10px;
            border-top: 4px solid var(--cor-principal);
            box-shadow: 0 4px 10px rgba(0,0,0,0.5);
            transition: transform 0.2s;
        }
        .feature-card:hover {
            transform: translateY(-5px);
        }
        .feature-card h3 {
            font-size: 20px;
            color: var(--cor-sucesso);
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .feature-card p {
            color: var(--cor-texto-secundaria);
            font-size: 16px;
        }

        /* FOOTER */
        footer {
            text-align: center;
            padding: 30px 0;
            background: var(--cor-fundo);
            border-top: 1px solid var(--cor-borda);
            font-size: 14px;
            color: var(--cor-texto-secundaria);
        }
        footer a {
            color: var(--cor-principal);
            text-decoration: none;
        }

        /* RESPONSIVIDADE */
        @media (max-width: 768px) {
            .hero { padding: 60px 10px; }
            .hero h2 { font-size: 36px; }
            .hero p { font-size: 18px; }
            .hero .cta-main { font-size: 18px; padding: 12px 25px; }
            .features { padding: 40px 0; }
            .features h2 { font-size: 30px; }
        }
    </style>
</head>
<body>

    <header>
        <div class="container">
            <h1>WalletlyBot 💰</h1>
            <a href="<?php echo $whatsapp_link; ?>" class="cta-header">Fale com o Bot</a>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="container">
                <p style="text-transform: uppercase; font-weight: 600; letter-spacing: 2px;">Micro-SaaS para Gestão de Compras</p>
                <h2>Nunca mais perca uma promoção no supermercado.</h2>
                <p>O WalletlyBot é o seu assistente pessoal de compras, acessível diretamente no WhatsApp. Registe gastos, compare preços históricos e organize as suas listas em tempo real.</p>
                
                <a href="<?php echo $whatsapp_link; ?>" class="cta-main">
                    Comece a Poupar Agora! 🚀
                </a>
            </div>
        </section>

        <section class="features">
            <div class="container">
                <h2>O que o WalletlyBot faz por si?</h2>
                <div class="features-grid">
                    
                    <div class="feature-card">
                        <h3>🛒 Registo Fácil via WhatsApp</h3>
                        <p>Basta enviar o item, a quantidade e o preço ("2x Leite 5,00"). O Bot guarda tudo na hora, sem a necessidade de apps complexas.</p>
                    </div>

                    <div class="feature-card">
                        <h3>📊 Histórico e Comparação de Preços</h3>
                        <p>O Bot lembra-se de quanto pagou da última vez. Se o preço subiu muito, ele avisa-o para que tome a melhor decisão de compra.</p>
                    </div>

                    <div class="feature-card">
                        <h3>📍 Sugestão de Estabelecimentos</h3>
                        <p>Ao partilhar a sua localização, o Bot lista os supermercados mais próximos para iniciar a compra, registando o local exato da sua poupança.</p>
                    </div>

                    <div class="feature-card">
                        <h3>📝 Listas de Compras Inteligentes</h3>
                        <p>Crie, edite e carregue as suas listas de compras diretamente na conversa, evitando esquecimentos e duplicidade de itens.</p>
                    </div>
                    
                    <div class="feature-card">
                        <h3>🔒 Painel de Controlo Seguro</h3>
                        <p>Aceda ao seu painel de relatórios (dashboard) através de um Link Mágico seguro, sem a necessidade de senhas. Veja os seus gastos e a poupança detalhadamente.</p>
                    </div>
                    
                    <div class="feature-card">
                        <h3>📉 Poupança Otimizada</h3>
                        <p>O objetivo é simples: maximizar a sua poupança. Analisamos promoções e o preço unitário para garantir que está a fazer a melhor compra possível.</p>
                    </div>

                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            &copy; <?php echo date("Y"); ?> WalletlyBot | Um Micro-SaaS by Merkee.
        </div>
    </footer>

</body>
</html>