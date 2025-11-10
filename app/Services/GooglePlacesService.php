<?php
// ---
// /app/Services/GooglePlacesService.php
// Responsável por comunicar com a Google Places API
// ---

require_once __DIR__ . '/../../config/api_keys.php'; 

class GooglePlacesService {
    
    private string $apiKey;
    private string $apiUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json';

    public function __construct() {
        if (!defined('GOOGLE_PLACES_API_KEY')) {
            throw new Exception("GOOGLE_PLACES_API_KEY não definida em config/api_keys.php");
        }
        $this->apiKey = GOOGLE_PLACES_API_KEY;
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
        curl_close($ch);

        if ($httpCode != 200) {
            // Se falhar, apenas não retorna nada. O log principal (webhook.php)
            // pode apanhar o erro se quisermos depurar.
            return []; 
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