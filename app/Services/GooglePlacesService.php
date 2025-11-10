<?php
// ---
// /app/Services/GooglePlacesService.php
// (VERSÃO COM NAMESPACE e getenv())
// ---

// 1. Define o Namespace
namespace App\Services;

// 2. Importa classes globais
use Exception;

class GooglePlacesService {
    
    private string $apiKey;
    private string $apiUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json';

    public function __construct() {
        // 3. (A CORREÇÃO) Lê do .env
        $this->apiKey = getenv('GOOGLE_PLACES_API_KEY');
        if (empty($this->apiKey)) {
            throw new Exception("GOOGLE_PLACES_API_KEY não definida no .env");
        }
    }

    /**
     * Busca locais no Google Places API
     *
     * @param string $nomeMercado O texto da busca (ex: "Mercado Bom Preço")
     * @param string $cidadeEstado O contexto (ex: "Maringá - PR")
     * @return array Lista de locais encontrados (ou vazia)
     */
    public function buscarLocais(string $nomeMercado, string $cidadeEstado): array
    {
        $query = $nomeMercado . " em " . $cidadeEstado;
        
        $params = http_build_query([
            'input' => $query,
            'inputtype' => 'textquery',
            'fields' => 'name,formatted_address,place_id',
            'key' => $this->apiKey,
            'language' => 'pt-BR'
        ]);

        $urlCompleta = $this->apiUrl . "?" . $params;

        $ch = curl_init($urlCompleta);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode != 200) {
            // (Lança exceção para o BotController/Webhook apanhar)
             throw new Exception(
                "Falha na API do Google Places. HTTP $httpCode. Resposta: $responseJson. Erro cURL: $curlError"
            );
        }

        $data = json_decode($responseJson, true);

        if ($data['status'] !== 'OK' || empty($data['candidates'])) {
            return []; // Nenhum resultado
        }

        $locaisFormatados = [];
        foreach ($data['candidates'] as $local) {
            $locaisFormatados[] = [
                'nome_google' => $local['name'],
                'endereco' => $local['formatted_address'],
                'place_id' => $local['place_id']
            ];
        }

        return $locaisFormatados;
    }
}
?>