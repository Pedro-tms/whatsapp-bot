<?php
$ACCESS_TOKEN = "SEU_ACCESS_TOKEN_AQUI";
$ID_CONTA_TELEFONICA = "SEU_ID_CONTA_TELEFONICA_AQUI";


$numero_cliente = "5521999998888"; 
$mensagem_humano = "Olá! Aqui é o barbeiro. Vi que você quer agendar. Qual horário?";




$url = "https://graph.facebook.com/v19.0/" . $ID_CONTA_TELEFONICA . "/messages";

$post_data = [
    "messaging_product" => "whatsapp",
    "to" => $numero_cliente, 
    "type" => "text",
    "text" => [
        "preview_url" => false,
        "body" => $mensagem_humano 
    ]
];

$json_data = json_encode($post_data);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $ACCESS_TOKEN, 
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>Resultado do Envio</h3>";
echo "<p>Status HTTP: $http_code</p>";
echo "<p>Resposta da API:</p>";
echo "<pre>$response</pre>";

?>