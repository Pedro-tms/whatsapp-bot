<?php

$VERIFY_TOKEN = "SEU_VERIFY_TOKEN_AQUI"; 
$ACCESS_TOKEN = "SEU_ACCESS_TOKEN_AQUI";
$ID_CONTA_TELEFONICA = "SEU_ID_CONTA_TELEFONICA_AQUI";

$DB_HOST = "localhost"; 
$DB_USER = "SEU_USUARIO_DB";
$DB_PASS = "SUA_SENHA_DB";
$DB_NAME = "SEU_BANCO_DB";

try {
    $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($db->connect_error) {
        throw new Exception("Erro de conexão com o DB: " . $db->connect_error);
    }
    $db->set_charset("utf8mb4");
} catch (Exception $e) {
    file_put_contents('log_meta.txt', $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500); 
    exit;
}

if (isset($_REQUEST['hub_mode']) && $_REQUEST['hub_mode'] == 'subscribe') {
    if (isset($_REQUEST['hub_verify_token']) && $_REQUEST['hub_verify_token'] == $VERIFY_TOKEN) {
        echo $_REQUEST['hub_challenge'];
        http_response_code(200);
        exit;
    } else {
        http_response_code(403);
        exit;
    }
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
file_put_contents('log_meta.txt', "[RECEBIDO] " . $input . PHP_EOL, FILE_APPEND);

if (isset($data['entry'][0]['changes'][0]['value']['messages'][0]['text'])) {
    
    $message_data = $data['entry'][0]['changes'][0]['value']['messages'][0];
    
    $telefone_cliente = $message_data['from'];
    $texto_recebido = $message_data['text']['body'];
    $nome_cliente = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'];
        
    list($id_conversa, $status_conversa) = getOrCreateConversation($db, $telefone_cliente, $nome_cliente);

    saveMessage($db, $id_conversa, 'cliente', $texto_recebido);

    if ($status_conversa == 'bot') {
        
        $mensagem = strtolower(trim($texto_recebido));
        $resposta_para_enviar = null;

        switch($mensagem) {
            case "oi":
            case "olá":
                $resposta_para_enviar = "Olá! 👋 Bem-vindo à Barbearia Piriquito!\nDigite /menu para ver as opções.";
                break;
            case "/menu":
                $menu = "💈 Menu Barbearia Piriquito:\n";
                $menu .= "/agenda - Ver horários disponíveis\n";
                $menu .= "/precos - Lista de preços\n";
                $menu .= "/contato - Nosso telefone e endereço";
                $resposta_para_enviar = $menu;
                break;
            case "/agenda":
                $resposta_para_enviar = "📅 Horários disponíveis:\nSeg-Sex: 09h-19h\nSáb: 09h-14h\nAgende enviando seu nome e horário desejado.";
                break;
            case "/precos":
                $precos = "💰 Tabela de preços:\nCorte: R$ 35\nBarba: R$ 20\nCorte + Barba: R$ 50";
                $resposta_para_enviar = $precos;
                break;
            case "/contato":
                $resposta_para_enviar = "📞 Contato:\nTelefone: (99) 99999-9999\nEndereço: Rua Exemplo, 123, Cidade/UF";
                break;
            default:
                $resposta_para_enviar = "Não entendi. Digite /menu para ver as opções disponíveis.";
                break;
        }

        if ($resposta_para_enviar !== null) {
            enviarMensagem($telefone_cliente, $resposta_para_enviar, $ACCESS_TOKEN, $ID_CONTA_TELEFONICA);
            
            saveMessage($db, $id_conversa, 'bot', $resposta_para_enviar);
        }
    }
}

$db->close();
http_response_code(200);
echo "OK";

/**
 * @return array 
 */
function getOrCreateConversation($db, $telefone, $nome) {
    // Tenta criar (ou atualizar o nome/data)
    $stmt = $db->prepare("INSERT INTO conversas (telefone_cliente, nome_cliente, data_ultima_msg) 
                         VALUES (?, ?, NOW()) 
                         ON DUPLICATE KEY UPDATE nome_cliente = ?, data_ultima_msg = NOW()");
    $stmt->bind_param("sss", $telefone, $nome, $nome);
    $stmt->execute();
    
    $stmt = $db->prepare("SELECT id, status FROM conversas WHERE telefone_cliente = ?");
    $stmt->bind_param("s", $telefone);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $conversa = $resultado->fetch_assoc();
    
    return [$conversa['id'], $conversa['status']];
}

function saveMessage($db, $id_conversa, $remetente, $conteudo) {
    if ($id_conversa) {
        $stmt_msg = $db->prepare("INSERT INTO mensagens (id_conversa, remetente, conteudo) 
                                 VALUES (?, ?, ?)");
        $stmt_msg->bind_param("iss", $id_conversa, $remetente, $conteudo);
        $stmt_msg->execute();
    }
}

function enviarMensagem($para, $texto, $token, $id_conta) {
    $url = "https://graph.facebook.com/v19.0/" . $id_conta . "/messages";
    $post_data = [
        "messaging_product" => "whatsapp", "to" => $para, "type" => "text",
        "text" => ["preview_url" => false, "body" => $texto]
    ];
    $json_data = json_encode($post_data);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents('log_meta.txt', "[ENVIO BOT] HTTP $http_code | Resp: $response" . PHP_EOL, FILE_APPEND);
}
?>