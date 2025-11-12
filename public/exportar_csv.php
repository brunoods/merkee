<?php
// ---
// /public/exportar_csv.php
// (Funcionalidade #16: Exportar Histórico em CSV)
// ---

session_start();

// 1. Segurança: Verifica se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("Acesso negado.");
}

// 2. Carrega as dependências
require_once __DIR__ . '/../config/bootstrap.php';
use App\Models\Compra;
use DateTime;
use Exception;

$userId = (int)$_SESSION['user_id'];

try {
    $pdo = getDbConnection();
    
    // 3. Busca os dados brutos do histórico
    $compras = Compra::findAllCompletedByUser($pdo, $userId);

    if (empty($compras)) {
        http_response_code(204); // No Content
        die("Nenhum dado de compra finalizada para exportar.");
    }

    // 4. Configura os cabeçalhos para forçar o download do CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="historico_compras_walletlybot_' . date('Ymd') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Abre o ponteiro de saída
    $output = fopen('php://output', 'w');

    // 5. Escreve os cabeçalhos da tabela
    fputcsv($output, [
        'ID_COMPRA',
        'DATA_FIM',
        'ESTABELECIMENTO',
        'GASTO_TOTAL',
        'POUPADO_TOTAL',
        'DATA_COMPLETA'
    ], ';'); // Usamos ponto e vírgula como delimitador (padrão brasileiro)

    // 6. Escreve os dados das linhas
    foreach ($compras as $compra) {
        $dataFim = new DateTime($compra['data_fim']);
        
        // Formata os valores para ter ponto decimal (padrão CSV)
        $gastoTotal = number_format($compra['total_gasto'], 2, '.', '');
        $poupadoTotal = number_format($compra['total_poupado'], 2, '.', '');

        fputcsv($output, [
            $compra['id'],
            $dataFim->format('d/m/Y'),
            $compra['estabelecimento_nome'],
            $gastoTotal,
            $poupadoTotal,
            $compra['data_fim']
        ], ';');
    }

    // Fecha o ponteiro
    fclose($output);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die("Erro interno do servidor ao gerar o CSV: " . $e->getMessage());
}