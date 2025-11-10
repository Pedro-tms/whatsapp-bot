<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Api extends CI_Controller {

    private $META_ACCESS_TOKEN = "SEU_ACCESS_TOKEN_AQUI";
    private $META_ID_CONTA = "SEU_ID_CONTA_TELEFONICA_AQUI";

    public function __construct() {
        parent::__construct();
        
        $this->load->database(); 
        
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            exit(0);
        }
    }

    public function conversas() {
        $query = $this->db->query("SELECT * FROM conversas ORDER BY data_ultima_msg DESC");
        $this->json_output($query->result());
    }


    public function mensagens($telefone) {
        if (empty($telefone)) {
            $this->json_output(['status' => 'error', 'message' => 'Telefone não fornecido.'], 400);
            return;
        }

        $query = $this->db->query(
            "SELECT m.* FROM mensagens m
             JOIN conversas c ON m.id_conversa = c.id
             WHERE c.telefone_cliente = ?
             ORDER BY m.data_envio ASC",
             [$telefone]
        );
        $this->json_output($query->result());
    }

    public function assumir() {
        $input = json_decode(file_get_contents('php://input'));
        $telefone = $input->telefone ?? null;

        if ($telefone) {
            $this->db->query("UPDATE conversas SET status = 'human' WHERE telefone_cliente = ?", [$telefone]);
            $this->json_output(['status' => 'success', 'message' => 'Conversa assumida pelo humano.']);
        } else {
            $this->json_output(['status' => 'error', 'message' => 'Telefone não fornecido.'], 400);
        }
    }
    
    public function devolver() {
        $input = json_decode(file_get_contents('php://input'));
        $telefone = $input->telefone ?? null;

        if ($telefone) {
            $this->db->query("UPDATE conversas SET status = 'bot' WHERE telefone_cliente = ?", [$telefone]);
            $this->json_output(['status' => 'success', 'message' => 'Conversa devolvida para o bot.']);
        } else {
            $this->json_output(['status' => 'error', 'message' => 'Telefone não fornecido.'], 400);
        }
    }

    public function enviar() {
        $input = json_decode(file_get_contents('php://input'));
        $telefone = $input->telefone ?? null;
        $texto = $input->texto ?? null;

        if (!$telefone || !$texto) {
            $this->json_output(['status' => 'error', 'message' => 'Telefone e texto são obrigatórios.'], 400);
            return;
        }

        $envio_api = $this->enviar_mensagem_meta($telefone, $texto);

        if ($envio_api['success']) {
            $this->salvar_mensagem_db($telefone, 'humano', $texto);
            $this->json_output(['status' => 'success', 'message' => 'Mensagem enviada.']);
        } else {
            $this->json_output(['status' => 'error', 'message' => 'Erro ao enviar pela Meta API.', 'api_response' => $envio_api['response']], 500);
        }
    }

    private function enviar_mensagem_meta($para, $texto) {
        $url = "https://graph.facebook.com/v19.0/" . $this->META_ID_CONTA . "/messages";
        $post_data = ["messaging_product" => "whatsapp", "to" => $para, "type" => "text", "text" => ["preview_url" => false, "body" => $texto]];
        $json_data = json_encode($post_data);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->META_ACCESS_TOKEN, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['success' => ($http_code == 200), 'response' => json_decode($response)];
    }

    private function salvar_mensagem_db($telefone, $remetente, $conteudo) {
        $conversa = $this->db->get_where('conversas', ['telefone_cliente' => $telefone])->row();
        
        if ($conversa) {
            $this->db->insert('mensagens', [
                'id_conversa' => $conversa->id,
                'remetente' => $remetente,
                'conteudo' => $conteudo
            ]);
        }
    }

    private function json_output($data, $http_code = 200) {
        $this->output
             ->set_content_type('application/json')
             ->set_status_header($http_code)
             ->set_output(json_encode($data));
    }
}