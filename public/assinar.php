<?php
// ---
// /public/assinar.php
// (VERSÃO CORRIGIDA para o SDK dx-php v2)
// ---

// 1. Inicialização
require_once __DIR__ . '/../config/bootstrap.php';

// ATENÇÃO: Inicia a sessão para obteres o ID do utilizador
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php"); 
    exit;
}

// 2. Importar Classes do SDK
// --- (INÍCIO DA CORREÇÃO) ---
use MercadoPago\MercadoPagoConfig; // Substitui 'use MercadoPago\SDK;'
use MercadoPago\Client\Preapproval\PreapprovalClient;
// --- (FIM DA CORREÇÃO) ---

try {
    // 3. Configurar SDK (Lê o token do .env que o bootstrap.php carregou)
    $accessToken = $_ENV['MP_ACCESS_TOKEN'] ?? getenv('MP_ACCESS_TOKEN');
    if (empty($accessToken)) {
        throw new Exception("MP_ACCESS_TOKEN não está definido no ficheiro .env");
    }
    // --- (INÍCIO DA CORREÇÃO) ---
    MercadoPagoConfig::setAccessToken($accessToken); // Substitui 'SDK::setAccessToken(...)'
    // --- (FIM DA CORREÇÃO) ---

    // 4. Obter ID do Utilizador
    $userId = (int)$_SESSION['user_id'];

    // 5. Configurações da Assinatura
    $valorPlano = 10.00; 
    $descricaoPlano = "Assinatura Mensal - Plano Premium";
    $urlBase = "https://" . $_SERVER['HTTP_HOST']; 

    // 6. Criar o Cliente de "Preapproval" (Assinatura)
    $client = new PreapprovalClient();
    $request = [
        "reason" => $descricaoPlano,
        "auto_recurring" => [
            "frequency" => 1,
            "frequency_type" => "months",
            "transaction_amount" => $valorPlano,
            "currency_id" => "BRL" // TODO: Muda para a tua moeda (ex: "EUR")
        ],
        "external_reference" => (string)$userId, 
        "back_url" => $urlBase . "/dashboard.php", 
        "notification_url" => $urlBase . "/webhook_mercadopago.php",
        "status" => "PENDING"
    ];

    // 7. Criar a Assinatura no Mercado Pago
    $preapproval = $client->create($request);

    if ($preapproval && isset($preapproval->init_point)) {
        // 8. Redirecionar o Utilizador para a página de pagamento do MP
        header("Location: " . $preapproval->init_point);
        exit;
    } else {
        throw new Exception("Não foi possível criar o link de assinatura.");
    }

} catch (Exception $e) {
    // 9. Lidar com erros
    die("Erro ao processar a assinatura: "." " . $e->getMessage());
}