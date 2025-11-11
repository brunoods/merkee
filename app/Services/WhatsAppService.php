<?php
// ---
// /app/Services/WhatsAppService.php
// (VERSÃO CORRETA - API META CLOUD LENDO DO .ENV COM TRIM())
// ---

namespace App\Services;

use Exception; 

class WhatsAppService {
    
    private string $apiUrl;
    private string $accessToken;
    private string $phoneId;

    public function __construct() {
        
        // 1. Lemos as variáveis do .env e limpamos (trim)
        // Esta é a correção de robustez para evitar erros de "parse"
        $accessTokenRaw = $_ENV['META_ACCESS_TOKEN'] ?? getenv('META_ACCESS_TOKEN');
        $phoneIdRaw = $_ENV['WHATSAPP_PHONE_ID'] ?? getenv('WHATSAPP_PHONE_ID');
        
        $this->accessToken = trim($accessTokenRaw); // <--- Correção essencial
        $this->phoneId = trim($phoneIdRaw);     // <--- Correção essencial

        // 2. Lemos a URL base da API
        $this->apiUrl = $_ENV['WHATSAPP_API_URL'] ?? getenv('WHATSAPP_API_URL') ?? 'https://graph.whatsapp.com/v24.0/';
        
        // 3. Verificamos as chaves limpas
        if (empty($this->accessToken) || empty($this->phoneId)) {
            throw new Exception("Configurações da API Meta (META_ACCESS_TOKEN ou WHATSAPP_PHONE_ID) não definidas no .env");
        }
    }

    /**
     * @param string $to_phone (ID do destinatário)
     * @param string $message (Corpo da mensagem)
     * @return bool (true se sucesso)
     * @throws Exception (em caso de falha da API)
     */
    public function sendMessage(string $to_phone, string $message): bool
    {
        // 4. Endpoint e Payload da Meta
        $endpoint = $this->apiUrl . $this->phoneId . '/messages';
        
        $payload = json_encode([
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $to_phone,
            "type" => "text",
            "text" => [ "preview_url" => false, "body" => $message ]
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        // 5. Header de Autenticação (Bearer Token)
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessToken // Usa o token limpo
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 6. Tratamento de Erro
        if ($httpCode < 200 || $httpCode >= 300) {
            // Esta é a linha que aparece nos logs de erro
            throw new Exception(
                "Falha na API da Meta. HTTP $httpCode. Resposta: $response. Erro cURL: $curlError"
            );
        }
        
        return true;
    }
}