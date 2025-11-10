<?php
// ---
// /app/Services/GooglePlacesService.php
// (VERSÃO ATUALIZADA COM BUSCA POR PROXIMIDADE)
// ---

namespace App\Services;

use Exception;

class GooglePlacesService {
    
    private string $apiKey;
    private string $textSearchUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json';
    private string $nearbySearchUrl = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json'; // (A função que faltava)

    public function __construct() {
        // (Lógica de carregar a API Key do .env está correta)
        $this->apiKey = $_ENV['GOOGLE_PLACES_API_KEY'] ?? getenv('GOOGLE_PLACES_API_KEY');
        if (empty($this->apiKey)) {
            throw new Exception("GOOGLE_PLACES_API_KEY não definida no .env");
        }
    }

    /**
     * (NOVO MÉTODO - A CORREÇÃO ESTÁ AQUI)
     * Busca supermercados próximos com base na latitude e longitude.
     */
    public function buscarSupermercadosProximos(float $latitude, float $longitude): array
    {
        $params = http_build_query([
            'location' => $latitude . ',' . $longitude,
            'rankby' => 'distance', // Ordena pelo mais próximo
            'type' => 'supermarket', // Filtra apenas por supermercados
            'key' => $this->apiKey,
            'language' => 'pt-BR'
        ]);

        $urlCompleta = $this->nearbySearchUrl . "?" . $params;
        $data = $this->fazerRequestApi($urlCompleta);

        if ($data['status'] !== 'OK' || empty($data['results'])) {
            return []; // Nenhum resultado
        }

        $locaisFormatados = [];
        // Limita aos 3 primeiros
        $resultados = array_slice($data['results'], 0, 3); 
        
        foreach ($resultados as $local) {
            $locaisFormatados[] = [
                'nome_google' => $local['name'],
                'endereco' => $local['vicinity'] ?? 'Endereço não disponível', // 'vicinity' é o endereço curto
                'place_id' => $local['place_id']
            ];
        }

        return $locaisFormatados;
    }


    /**
     * (MÉTODO ANTIGO - AINDA O USAMOS)
     * Busca locais no Google Places API
     */
    public function buscarLocais(string $nomeMercado, string $cidadeEstado): array
    {
        $params = http_build_query([
            'input' => $nomeMercado . " em " . $cidadeEstado,
            'inputtype' => 'textquery',
            'fields' => 'name,formatted_address,place_id',
            'key' => $this->apiKey,
            'language' => 'pt-BR'
        ]);

        $urlCompleta = $this->textSearchUrl . "?" . $params;
        $data = $this->fazerRequestApi($urlCompleta);
        
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
    
    /**
     * (NOVO HELPER - A CORREÇÃO ESTÁ AQUI)
     * Centraliza a lógica de fazer a chamada cURL
     */
    private function fazerRequestApi(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode != 200) {
             throw new Exception(
                "Falha na API do Google Places. HTTP $httpCode. Resposta: $responseJson. Erro cURL: $curlError"
            );
        }
        
        return json_decode($responseJson, true);
    }
}
?>