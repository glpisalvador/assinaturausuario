<?php

/**
 * Plugin Assinatura de Usuário - endpoint AJAX (sempre JSON).
 * previa (POST): assinatura do editor com as variáveis preenchidas, como vai aparecer no chamado.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginAssinaturausuarioConfig::class;
$responder = function (array $dados) use ($C): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $C::tokenCsrf() : '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem) => $responder(['success' => false, 'mensagem' => $mensagem]);

if (!Session::getLoginUserID()) {
    $falhar('Sessão expirada. Recarregue a página.');
}

try {
    switch ((string) ($_REQUEST['action'] ?? '')) {
        case 'previa':
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                $falhar('Requisição inválida.');
            }
            $usuario = (int) ($_POST['users_id'] ?? 0);
            if ($usuario === 0 && $C::ehAdmin()) {
                $usuario = (int) Session::getLoginUserID();
            } elseif (!PluginAssinaturausuarioPreferencia::podeEditar($usuario)) {
                $falhar('Sem permissão.');
            }
            $conteudo = (string) ($_POST['conteudo'] ?? '');
            $A = PluginAssinaturausuarioAssinatura::class;
            $html = $A::vazio($conteudo) ? '' : \Glpi\RichText\RichText::getSafeHtml($A::montar($conteudo, $usuario, -1, false));
            $responder(['success' => true, 'html' => $html]);
    }
    $falhar('Ação desconhecida.');
} catch (\Throwable $e) {
    error_log('Plugin assinaturausuario: ' . $e->getMessage());
    $falhar('Não foi possível gerar a prévia.');
}
