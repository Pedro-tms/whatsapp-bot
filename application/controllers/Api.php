<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Api extends CI_Controller {

    // (Coloque seus tokens aqui ou no config.php do CodeIgniter)
    private $META_ACCESS_TOKEN = "SEU_ACCESS_TOKEN_AQUI";
    private $META_ID_CONTA = "SEU_ID_CONTA_TELEFONICA_AQUI";

    public function __construct() {
        parent::__construct();
        // Carrega o 'database' para todas as funções
        $this->load->database(); 
        
        // Permite que seu React (em outro domínio) acesse esta API
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
    }

    // Rota: GET /api/conversas
    // Lista todas as conversas para o painel
    public function conversas() {
        $query = $this->db->query("SELECT * FROM conversas ORDER BY data_ultima_msg DESC");
        $this->json_output($query->result());
    }

    // Rota: GET /api/mensagens/5521999998888
    // Pega o histórico de um cliente específico
    public function mensagens($telefone) {
        $query = $this->db->query(
            "SELECT m.* FROM mensagens m
             JOIN conversas c ON m.id_conversa = c.id
             WHERE c.telefone_cliente = ?
             ORDER BY m.data_envio ASC",
             [$telefone]
        );
        $this->json_output($query->result());
    }

    // Rota: POST /api/assumir
    // Muda o status da conversa para 'human'
    public function assumir() {
        $input = json_decode(file_get_contents('php://input'));
        $telefone = $input->telefone;

        if ($telefone) {
            $this->db->query("UPDATE conversas SET status = 'human' WHERE telefone_cliente = ?", [$telefone]);
            $this->json_output(['status' => 'success', 'message' => 'Conversa assumida pelo humano.']);
        } else {
            $this->json_output(['status' => 'error', 'message' => 'Telefone não fornecido.'], 400);
        }
    }
    
    // Rota: POST /api/devolver
    // Devolve a conversa para o bot
    public function devolver() {
        $input = json_decode(file_get_contents('php://input'));
        $telefone = $input->telefone;

        if ($telefone) {
            $this->db->query("UPDATE conversas SET status = 'bot' WHERE telefone_cliente = ?", [$telefone]);
            $this->json_output(['status' => 'success', 'message' => 'Conversa devolvida para o bot.']);
        } else {
            $this->json_output(['status' => 'error', 'message' => 'Telefone não fornecido.'], 400);
        }
    }

    // Rota: POST /api/enviar
    // O HUMANO envia uma mensagem pelo painel
    public function enviar() {
        $input = json_decode(file_get_contents('php://input'));
        $telefone = $input->telefone;
        $texto = $input->texto;

        if (!$telefone || !$texto) {
            $this->json_output(['status' => 'error', 'message' => 'Telefone e texto são obrigatórios.'], 400);
            return;
        }

        // 1. Tenta enviar pela API da Meta
        $envio_api = $this->enviar_mensagem_meta($telefone, $texto);

        if ($envio_api['success']) {
            // 2. Se enviou, salva no banco
            $this->salvar_mensagem_db($telefone, 'humano', $texto);
            $this->json_output(['status' => 'success', 'message' => 'Mensagem enviada.', 'api_response' => $envio_api['response']]);
        } else {
            // 3. Se deu erro, avisa o painel
            $this->json_output(['status' => 'error', 'message' => 'Erro ao enviar pela Meta API.', 'api_response' => $envio_api['response']], 500);
        }
    }

    // ===================================================================
    //      FUNÇÕES PRIVADAS DE AJUDA
    // ===================================================================

    /**
     * Envia a mensagem via cURL para a API da Meta
     * (A lógica do seu 'enviar.php', agora dentro do CodeIgniter)
     */
    private function enviar_mensagem_meta($para, $texto) {
        $url = "https://graph.facebook.com/v19.0/" . $this->META_ID_CONTA . "/messages";
        $post_data = [
            "messaging_product" => "whatsapp", "to" => $para, "type" => "text",
            "text" => ["preview_url" => false, "body" => $texto]
        ];
        $json_data = json_encode($post_data);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->META_ACCESS_TOKEN,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // A API da Meta retorna 200 em sucesso
        if ($http_code == 200) {
            return ['success' => true, 'response' => json_decode($response)];
        } else {
            return ['success' => false, 'response' => json_decode($response)];
        }
    }

    /**
     * Salva a mensagem (do humano ou bot) no banco
     */
    private function salvar_mensagem_db($telefone, $remetente, $conteudo) {
        // 1. Achar o ID da conversa
        $conversa = $this->db->get_where('conversas', ['telefone_cliente' => $telefone])->row();
        
        if ($conversa) {
            // 2. Inserir a mensagem
            $this->db->insert('mensagens', [
                'id_conversa' => $conversa->id,
                'remetente' => $remetente,
                'conteudo' => $conteudo
            ]);
        }
    }

    /**
     * Helper para formatar a saída como JSON
     */
    private function json_output($data, $http_code = 200) {
        $this->output
             ->set_content_type('application/json')
             ->set_status_header($http_code)
             ->set_output(json_encode($data));
    }
}